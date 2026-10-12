#!/usr/bin/env bash
# Builds deploy.zip for uploading to AWS Elastic Beanstalk.
# Usage: scripts/build-deploy-zip.sh   (run from anywhere inside the repo)
set -euo pipefail

# Work from the repo root so paths in the zip start at the app root.
cd "$(dirname "$0")/.."

fail() {
    echo "" >&2
    echo "ERROR: $*" >&2
    echo "deploy.zip was NOT built. Fix the problem above and run this script again." >&2
    rm -f deploy.zip
    exit 1
}

# public/hot is written by the Vite dev server (npm run dev / composer dev).
# If it ships, Laravel points every page's CSS and JS at http://127.0.0.1:5173,
# which doesn't exist for visitors, so the live site loads with no styles.
if [ -e public/hot ]; then
    fail "public/hot exists, which means the Vite dev server is running (or crashed without cleaning up).
       Stop 'composer dev' / 'npm run dev', then delete the file:  rm public/hot"
fi

echo "Building front-end assets from scratch..."
rm -rf public/build
npm run build

# Check the build: the manifest, both entry points, the font files, and that
# every file the manifest lists really exists.
echo "Checking the build..."
[ -f public/build/manifest.json ] || fail "public/build/manifest.json is missing after 'npm run build'."
php -r '
    $manifest = json_decode(file_get_contents("public/build/manifest.json"), true);
    if (! is_array($manifest)) { fwrite(STDERR, "manifest.json is not valid JSON\n"); exit(1); }
    $files = [];
    foreach (["resources/css/app.css", "resources/js/app.js"] as $entry) {
        if (empty($manifest[$entry]["file"])) { fwrite(STDERR, "manifest has no entry for $entry\n"); exit(1); }
    }
    foreach ($manifest as $chunk) {
        $files[] = $chunk["file"];
        foreach ($chunk["assets"] ?? [] as $asset) { $files[] = $asset; }
        foreach ($chunk["css"] ?? [] as $css) { $files[] = $css; }
    }
    foreach (["fraunces-latin-opsz-normal", "public-sans-latin-wght-normal"] as $font) {
        if (! preg_grep("/\/$font-[^\/]+\.woff2$/", $files)) { fwrite(STDERR, "self-hosted font $font.woff2 is not in the build\n"); exit(1); }
    }
    foreach (array_unique($files) as $file) {
        if (! is_file("public/build/$file")) { fwrite(STDERR, "manifest lists public/build/$file but it does not exist\n"); exit(1); }
    }
' || fail "The front-end build is incomplete (see the message above)."

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
    -x "public/hot" \
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
    -x "storage/app/fraud-eval-cache/*" \
    -x "bootstrap/cache/*.php"

# Add back files that are safe and needed:
# - .env.example is useful documentation.
# - The .gitignore placeholders keep the empty storage folders in the zip.
#   Without them the folders vanish, and Laravel fails with a 500 error when
#   it tries to save a session file into a folder that doesn't exist.
zip -q deploy.zip .env.example \
    storage/framework/sessions/.gitignore \
    storage/framework/cache/data/.gitignore

# Check the zip itself, not just the folder it was made from.
echo "Checking deploy.zip..."
contents="$(unzip -Z1 deploy.zip)"
if grep -qx "public/hot" <<< "$contents"; then
    fail "deploy.zip contains public/hot."
fi
if grep -qEx '\.env|.*\.pem|.*\.key|storage/app/fraud-eval-cache/.+' <<< "$contents"; then
    fail "deploy.zip contains a secret or the eval cache:
$(grep -Ex '\.env|.*\.pem|.*\.key|storage/app/fraud-eval-cache/.+' <<< "$contents")"
fi
for file in public/build/manifest.json $(cd public/build && find assets -type f | sed 's|^|public/build/|'); do
    grep -qxF "$file" <<< "$contents" || fail "deploy.zip is missing $file."
done

echo ""
echo "Front-end files in deploy.zip:"
grep '^public/build/.' <<< "$contents" | grep -v '/$' | sort | sed 's/^/  /'
echo ""
echo "Done: $(du -h deploy.zip | cut -f1) deploy.zip"
echo "Upload it in the Elastic Beanstalk console (Upload and deploy)."
