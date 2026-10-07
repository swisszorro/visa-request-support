# Self-contained Gotenberg image with the Avenir fonts baked in, so LibreOffice
# renders the invitation letter (DOCX→PDF) in the correct typeface.
#
# Build context = THIS folder (gotenberg/). Fonts live in ./fonts.
#   docker build -t bwc-gotenberg .
FROM gotenberg/gotenberg:8

USER root

# Install the Avenir fonts (world-readable; LibreOffice runs as user 'gotenberg')
COPY fonts/*.ttf /usr/share/fonts/truetype/avenir/
RUN chmod 0644 /usr/share/fonts/truetype/avenir/*.ttf \
    && fc-cache -f -v \
    && echo "=== installed Avenir faces ===" \
    && fc-list | grep -i avenir | sort

USER gotenberg
# Gotenberg listens on :3000, route: POST /forms/libreoffice/convert
