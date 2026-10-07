#!/usr/bin/env bash
# Build the Avenir-Gotenberg image and deploy it to Google Cloud Run.
# Run from THIS folder:  bash deploy.sh
#
# Prereqs (once): gcloud auth login; gcloud config set project <ID>;
#   gcloud services enable run.googleapis.com cloudbuild.googleapis.com artifactregistry.googleapis.com
#   gcloud artifacts repositories create bwc --repository-format=docker --location=$REGION
set -euo pipefail

REGION="${REGION:-europe-west6}"                 # Zürich; override: REGION=... bash deploy.sh
SERVICE="${SERVICE:-gotenberg}"
PROJ="$(gcloud config get-value project 2>/dev/null)"
[ -n "$PROJ" ] || { echo "No gcloud project set: gcloud config set project <ID>"; exit 1; }

# unique tag so Cloud Run always rolls out the new image
TAG="$(date +%Y%m%d%H%M%S 2>/dev/null || echo v)"
IMG="$REGION-docker.pkg.dev/$PROJ/bwc/$SERVICE:$TAG"

echo "→ Building $IMG (context: $(pwd))"
# Cloud Build with an explicit Dockerfile path + this dir as context.
cat > /tmp/bwc-gotenberg-cloudbuild.yaml <<YAML
steps:
  - name: gcr.io/cloud-builders/docker
    args: ['build','-f','Dockerfile','-t','$IMG','.']
images: ['$IMG']
YAML
gcloud builds submit --config /tmp/bwc-gotenberg-cloudbuild.yaml .

echo "→ Deploying to Cloud Run: $SERVICE ($REGION)"
gcloud run deploy "$SERVICE" \
  --image "$IMG" --region "$REGION" \
  --port 3000 --memory 2Gi --cpu 1 \
  --min-instances 0 --max-instances 3 --timeout 300 \
  --allow-unauthenticated

URL="$(gcloud run services describe "$SERVICE" --region "$REGION" --format='value(status.url)')"
echo
echo "✅ Deployed: $URL"
echo "   Health:   curl -i $URL/health   (expect HTTP 200)"
echo "   Set in Hostpoint .env:  GOTENBERG_URL=$URL"
