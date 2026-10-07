# Berne World Cup – Visa Request Service
# PHP 8.2 + LibreOffice (DOCX->PDF) + poppler (PDF->PNG) + GD/zip/intl.
FROM php:8.2-cli

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        libreoffice-writer \
        poppler-utils \
        tesseract-ocr tesseract-ocr-eng \
        fonts-dejavu-core \
        fontconfig \
        libzip-dev \
        libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev procps \
        libicu-dev \
        unzip git curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd zip intl \
    && rm -rf /var/lib/apt/lists/*

# OCR-B trained models for the MRZ. The MRZ uses the OCR-B font, which the
# generic `eng` model misreads — these dedicated models read it correctly.
# Installed as both `mrz` (DoubangoTelecom) and `ocrb` (Shreeshrii) so
# TESSERACT_MRZ_LANG can point at either. Non-fatal if a download fails
# (runtime falls back to eng + char whitelist).
RUN set -eu; \
    TESSDATA="$(dirname "$(find /usr/share/tesseract-ocr -name eng.traineddata | head -1)")"; \
    echo "tessdata dir: $TESSDATA"; \
    curl -fsSL -o "$TESSDATA/mrz.traineddata" \
        https://raw.githubusercontent.com/DoubangoTelecom/tesseractMRZ/master/tessdata_best/mrz.traineddata \
        || echo "WARN: mrz.traineddata not fetched"; \
    curl -fsSL -o "$TESSDATA/ocrb.traineddata" \
        https://raw.githubusercontent.com/Shreeshrii/tessdata_ocrb/master/ocrb.traineddata \
        || echo "WARN: ocrb.traineddata not fetched"; \
    ls -la "$TESSDATA" | grep -E 'mrz|ocrb' || true

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts || composer install --no-dev --no-interaction --prefer-dist --no-scripts

COPY . .

# Install the Avenir fonts so LibreOffice renders the letter in the template's
# real typeface (otherwise DOCX→PDF substitutes a fallback font).
RUN if ls resources/fonts/*.ttf >/dev/null 2>&1; then \
        mkdir -p /usr/share/fonts/truetype/avenir && \
        cp resources/fonts/*.ttf /usr/share/fonts/truetype/avenir/ && \
        fc-cache -f >/dev/null && \
        echo "Avenir fonts installed:" && fc-list | grep -i avenir | sort; \
    else \
        echo "WARN: no Avenir TTFs in resources/fonts — PDF will use a fallback font"; \
    fi

# generous upload limits for 24 passport images
RUN { \
      echo "upload_max_filesize=64M"; \
      echo "post_max_size=128M"; \
      echo "memory_limit=512M"; \
      echo "max_execution_time=300"; \
    } > /usr/local/etc/php/conf.d/visa.ini

RUN mkdir -p storage/tmp storage/output && chmod -R 0775 storage

EXPOSE 8080
CMD ["php", "-d", "variables_order=EGPCS", "-S", "0.0.0.0:8080", "-t", "public"]
