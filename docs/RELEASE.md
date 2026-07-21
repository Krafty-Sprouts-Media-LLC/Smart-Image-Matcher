<!--
  Release process for Smart Image Matcher (GitHub → WordPress updates).
  @package SmartImageMatcher
  @since   3.0.8
-->

# Releasing Smart Image Matcher

Public updates are served from GitHub Releases via [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker).

## Tag format

Use **semver without a required `v` prefix**:

```bash
git tag 3.0.8
git push origin 3.0.8
```

`v3.0.8` still works (PUC strips a leading `v`), but the project convention is bare `X.Y.Z`.

## Checklist

1. Bump version in lockstep:
   - `smart-image-matcher.php` header `Version`
   - `SMART_IMAGE_MATCHER_VERSION` constant
   - `readme.txt` `Stable tag`
2. Add a `## [X.Y.Z] - DD/MM/YYYY` section to `CHANGELOG.md` (and a short `readme.txt` changelog entry).
3. Commit on `main` and push.
4. Create and push the tag (no `v` prefix):

   ```bash
   git tag 3.0.8
   git push origin main
   git push origin 3.0.8
   ```

5. GitHub Actions workflow `.github/workflows/release.yml` will:
   - Verify header / constant / Stable tag match the tag
   - Build `smart-image-matcher.zip` (`bin/build-zip.sh`)
   - Publish a public GitHub Release named `X.Y.Z` with the zip attached
   - Use the matching `CHANGELOG.md` section as the release body

WordPress sites then see the update through the in-plugin GitHub update checker.

## Disable GitHub updates (e.g. wp.org build)

```php
define( 'SMART_IMAGE_MATCHER_DISABLE_GITHUB_UPDATES', true );
```

Or:

```php
add_filter( 'smart_image_matcher_enable_github_updates', '__return_false' );
```
