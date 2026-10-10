#!/usr/bin/env bash
# Builds deploy.zip for uploading to AWS Elastic Beanstalk.
# Usage: scripts/build-deploy-zip.sh   (run from anywhere inside the repo)
set -euo pipefail

# Work from the repo root so paths in the zip start at the app root.
cd "$(dirname "$0")/.."

echo "Building front-end assets..."
npm run build

echo "Creating deploy.zip..."
rm -f deploy.zip
zip -r -q deploy.zip . \
    -x ".env" \
    -x ".env.*" \
    -x ".git/*" \
    -x "node_modules/*" \
    -x "vendor/*" \
    -x "tests/*" \
    -x "deploy.zip" \
    -x "database/*.sqlite*" \
    -x "storage/*.sqlite*" \
    -x "*.pem" \
    -x "*.key" \
    -x ".claude/*" \
    -x "*.DS_Store" \
    -x ".phpunit.result.cache" \
    -x "storage/logs/*.log" \
    -x "storage/framework/views/*.php" \
    -x "storage/framework/sessions/*" \
    -x "storage/framework/cache/data/*" \
    -x "bootstrap/cache/*.php"

# Add back files that are safe and needed:
# - .env.example is useful documentation.
# - The .gitignore placeholders keep the empty storage folders in the zip.
#   Without them the folders vanish, and Laravel fails with a 500 error when
#   it tries to save a session file into a folder that doesn't exist.
zip -q deploy.zip .env.example \
    storage/framework/sessions/.gitignore \
    storage/framework/cache/data/.gitignore

echo "Done: $(du -h deploy.zip | cut -f1) deploy.zip"
echo "Upload it in the Elastic Beanstalk console (Upload and deploy)."
