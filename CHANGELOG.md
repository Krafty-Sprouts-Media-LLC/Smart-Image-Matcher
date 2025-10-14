# Changelog

All notable changes to Smart Image Matcher will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.7.0] - 12/10/2025

### Added
- **🎨 MAJOR FEATURE: Image Carousel for Multiple Matches**
- Users can now browse through alternative matches for each heading
- Prev/Next navigation buttons to cycle through all matched images
- Counter display: "Image 2 of 3" shows position in match list
- ⭐ "Best Match" badge on #1 recommended image
- Real-time updates: Everything changes when navigating:
  - Image preview
  - Image title and filename
  - Confidence score and color coding
  - "View Full" link
  - AI reasoning (if available)
- **Keyboard Navigation**: Left/Right arrow keys to browse matches
- **User Choice**: Insert whichever image is currently displayed
- **Bulk Insert**: Uses currently selected image for each heading (not just top match)

### Improved
- User empowerment: Not forced to accept top match
- Better accuracy: Users can choose best contextual fit
- Visual comparison: Browse all options before deciding
- Handles similar image names perfectly (e.g., "Woodland Mosquito" vs "Woodland Malaria Mosquito")
- Educational: Users see all matches and understand scoring
- Professional UX: Like WordPress media selector

### Technical
- Added `currentIndices` object to track selected image per heading
- All matches stored in `data-all-matches` attribute (JSON)
- `updateCarouselDisplay()` function updates DOM dynamically
- No page refresh - pure JavaScript transitions
- Carousel controls only show if `matches.length > 1`
- Prev/Next buttons auto-disable at boundaries
- Golden gradient badge for recommended match

### UX Details
- Always starts with #1 (best score) by default
- Badge disappears when browsing to other matches
- Disabled buttons show with reduced opacity
- Smooth, instant transitions
- Keyboard accessible for power users

## [1.6.0] - 12/10/2025

### Fixed
- **CRITICAL: Improved Matching Accuracy for Similar Names**
- Now correctly differentiates between "Woodland Mosquito" vs "Woodland Malaria Mosquito"
- Previously both would match to "woodland-malaria-mosquito.jpg"
- Now "Woodland Mosquito" matches "woodland-mosquito.jpg" (100 pts) over "woodland-malaria-mosquito.jpg" (90 pts)

### Changed
- **Enhanced Scoring Algorithm**: Graduated penalty system for extra words
- 0 extra words = 10% BONUS (exact match preferred!)
- 1 extra word = 10% penalty
- 2 extra words = 18% penalty  
- 3+ extra words = 25% penalty
- Applied to all fields: filename, title, and alt text

### Improved
- Much better specificity matching
- Prefers exact matches over verbose filenames
- "woodland-mosquito.jpg" now outscores "woodland-malaria-mosquito.jpg" for "Woodland Mosquito" heading
- More accurate matching for similar image names
- Better differentiation between related images

### Technical
- Refactored penalty logic in `calculate_match_score()` method
- Removed old threshold-based penalty (only >3 words)
- Implemented graduated scale for ANY extra words
- Added 10% bonus for perfect word count matches
- Applied consistently across filename, title, and alt text fields

## [1.5.2] - 12/10/2025

### Changed
- **Reverted to Full Name**: Changed menu title back to "Smart Image Matcher"
- Removed "SIM" abbreviation based on user feedback
- Full name is clearer and more professional
- Consistent branding across all admin areas

### Fixed
- Menu now shows complete plugin name for better clarity
- No abbreviations that might confuse users

## [1.4.2] - 12/10/2025

### Changed
- **Reorganized Admin Menu Structure**: Now uses parent menu with submenus
- Main menu item: "Smart Image Matcher" (with dashicons-format-image icon)
  - Submenu: Settings (default page)
  - Submenu: Bulk Processor (placeholder for Phase 7)
- Removed duplicate menu entries in Settings and Tools
- All features accessible from one organized location

### Added
- Enhanced bulk processor placeholder page with roadmap
- Shows planned features for Phase 7:
  - Step 1: Select Posts (with filters)
  - Step 2: Configure Processing
  - Step 3: Review & Approve matches
  - Step 4: Monitor Progress
- "Coming Soon" notice with clear messaging
- Workaround instructions for current single-post workflow

### Fixed
- Removed duplicate `render_bulk_processor_page()` method from SIM_Admin
- Method now properly located only in SIM_Bulk class
- Eliminated menu confusion

### Improved
- Better navigation structure
- Clear separation: Settings vs Bulk Processing
- Professional placeholder with feature preview
- Icon in main menu for easy identification

## [1.4.1] - 12/10/2025

### Fixed
- **UI Cleanup**: Removed redundant emoji from modal tips section
- Tips now show dashicons lightbulb only (no emoji duplication)
- Cleaner, more professional appearance

## [1.4.0] - 12/10/2025

### Changed
- **🎯 MAJOR: Optimized Bulk Insert - ONE Revision Instead of Multiple**
- Bulk insert now creates ONE clean revision for all images (not 20 revisions for 20 images)
- Significantly improved performance: One database write instead of multiple
- One cache clear instead of multiple
- WordPress hooks fire once instead of per-image
- Cleaner revision history for users

### Improved
- **Atomic Bulk Operations**: All images inserted in one transaction (all-or-nothing)
- More predictable behavior: Success or fail as a unit
- Better error handling: Duplicates filtered before update
- Faster execution: Reduced database overhead
- Better performance: Parse blocks once, serialize once (Gutenberg)
- Cleaner undo: One revision to revert instead of finding the right one

### Technical
- Refactored `insert_all_images()` to build final content before update
- Added `bulk_insert_gutenberg()` for efficient Gutenberg batch processing
- Added `bulk_insert_html()` for efficient Classic Editor batch processing
- Single `wp_update_post()` call for all images in bulk operation
- Duplicate checking done upfront before content modification
- Logging shows "ONE revision created for X images"

### Fixed
- Eliminated revision bloat from bulk operations
- No more 20 revisions when inserting 20 images
- Clean revision history: "Inserted X images via Smart Image Matcher"

## [1.3.4] - 12/10/2025

### Fixed
- **Improved Special Character Handling**: Enhanced keyword extraction to properly handle separators
- Slashes, commas, pipes, colons, semicolons, parentheses, and brackets now replaced with spaces
- Prevents word merging: "Baltimore Oriole (Female/immature)" now correctly extracts as ['baltimore', 'oriole', 'female', 'immature']
- Previously would merge to "femaleimmature" - now properly separates all words

### Improved
- Better matching for headings with:
  - Species variations: "Baltimore Oriole (Female/immature)" or "Robin (Male/Female)"
  - Ranges: "Morning/Evening Routine" or "Spring/Summer Collection"
  - Alternatives: "Red/Orange Flowers" or "Large/Small Sizes"
  - Lists: "Birds: Cardinals, Robins, Blue Jays"
- More accurate keyword extraction from complex heading text
- Handles parenthetical information without losing keywords
- **Clarified Image Title Guidelines**: Added example showing titles should use natural language
  - Updated tips: "Kentucky Warbler" (natural) not "kentucky-warbler" (with dashes)
  - Image titles should be human-readable, not filename-style

### Technical
- Updated `extract_keywords()` method in `class-sim-matcher.php`
- Separators replaced with spaces before special character removal
- Handles: `/`, `,`, `|`, `;`, `:`, `(`, `)`, `[`, `]`
- Updated tips in both settings page and modal with clear title example

## [1.3.3] - 12/10/2025

### Added
- **Image Naming Best Practices Guide**: Added helpful tips section on settings page
- **Collapsible Tips in Modal**: Added expandable tips section in the matching modal
- Tips explain how to name images for better matching results
- Shows scoring priority: Filename (100) → Title (90) → Alt Text (85)
- Guidelines include:
  - Use descriptive filenames with word separators (dashes, underscores, or spaces)
  - Set meaningful image titles in Media Library
  - Add relevant alt text for SEO and accessibility
  - Match keywords from headings in image metadata
  - Avoid generic names like "IMG_001.jpg"

### Improved
- Better user education on image organization
- Helps users understand the matching algorithm
- Contextual help available right in the workflow
- Persistent reference guide on settings page
- Encourages SEO-friendly image practices

### Clarified
- **Flexible Naming Support**: Clarified that dashes, underscores, and spaces all work
- Dashes are SEO-recommended but NOT required
- Plugin handles all naming conventions equally
- Added note: "Dashes are SEO-recommended but any separator works!"

## [1.3.2] - 12/10/2025

### Changed
- **Image Display Enhancement**: Modal now shows BOTH image title and filename
- Title displayed first (if available), filename shown below for reference
- Provides complete information for users to verify matches
- Better transparency - users can see both descriptive title and technical filename

### Improved
- Enhanced UX with complete image metadata visibility
- Users can now cross-reference both title and filename with their headings
- More informed decision-making when selecting images to insert

## [1.3.1] - 12/10/2025

### Added
- **Image Title Display**: Modal now shows image title instead of filename for better comparison with heading text
- **Duplicate Detection**: Prevents re-insertion of images that already exist near the same heading
- Checks for existing images within 1000 characters (500 before, 1500 after heading position)
- Clear error logging when duplicate images are detected
- Falls back to showing filename if image title is empty

### Improved
- Better UX: Users can now easily compare image titles with their heading text
- Prevents accidental duplicate insertions when running the process multiple times
- More accurate image identification in modal (title is more descriptive than filename)
- Enhanced duplicate checking algorithm that considers position proximity

### Technical
- Added `title` field to match results in `SIM_Matcher::find_keyword_matches()`
- Updated JavaScript to display title with fallback to filename
- Implemented `image_exists_in_content()` helper method for duplicate detection
- Returns WP_Error with 'duplicate_image' code when duplicate detected
- Position-aware duplicate checking (not just global image presence)

## [1.3.0] - 12/10/2025

### Removed
- **MAJOR SIMPLIFICATION**: Removed undo functionality (unnecessary complexity)
- Removed 10-second countdown timers
- Removed "Reload Now" and "Cancel Auto-Reload" buttons
- Removed backup/restore transient storage
- Removed undo AJAX handler and action hook

### Changed
- Individual insert: Shows "Inserting..." notice → Success → Reload (0.8s)
- Bulk insert: Shows "Inserting X images..." notice → Success → Reload (1s)
- Clear modal notices (not browser prompts) - "Page will reload to show changes"
- Simplified UX - just insert and reload, no waiting or decisions

### Added
- **Warning notice**: "⚠️ Please Review" reminder to check matches before inserting
- Prompts users to verify images are relevant and uncheck incorrect matches
- In-modal notices showing insertion progress
- "Page will reload to show changes" message for both insert types
- Success confirmation before reload
- Progress indicators during insertion

### Improved
- Removed 100+ lines of unnecessary timer/undo code
- Faster workflow - clear visual feedback at each step
- Users can use WordPress revisions if they need to undo
- Simpler codebase, easier to maintain
- No browser alert() prompts - all messages in modal

## [1.1.1] - 12/10/2025

### Fixed
- **CRITICAL**: Fixed Gutenberg validation by REMOVING width/height from img tag
- Gutenberg expects exactly 3 attributes: src, alt, class (NOT width/height)
- Block comment attributes: ONLY id, sizeSlug, linkDestination
- Gutenberg automatically handles dimensions via "sizeSlug":"large"

### Changed
- Removed width/height from img tag (Gutenberg adds them automatically)
- Removed width/height from block attrs
- Clean img tag: `<img src="..." alt="..." class="wp-image-X"/>`
- Let Gutenberg's sizeSlug handle all responsive sizing

### Technical
- Gutenberg validation expects exact attribute match
- sizeSlug="large" tells Gutenberg to handle dimensions
- WordPress adds width/height when rendering on frontend
- Block comment must NOT include dimensions for validation to pass

## [1.1.0] - 12/10/2025

### Fixed
- **CRITICAL**: Proper Gutenberg support using WordPress block parser functions
- Detects Gutenberg vs Classic Editor using `has_blocks()`
- Uses `parse_blocks()` and `serialize_blocks()` for Gutenberg content
- Uses HTML insertion for Classic Editor
- Block validation errors eliminated by using WordPress block API

### Changed
- Complete rewrite to use WordPress native functions exclusively
- Gutenberg: `parse_blocks()`, `serialize_blocks()`, `render_block()`
- Classic Editor: HTML insertion with `wp_get_attachment_image()`
- Removed all manual block/HTML building
- Separate code paths for Gutenberg vs Classic Editor

### Technical
- Uses WordPress Block Editor API (parse_blocks, serialize_blocks)
- Creates proper Gutenberg block arrays with blockName, attrs, innerHTML
- WordPress handles all HTML generation via `wp_get_attachment_image()`
- Automatic responsive images, srcset, and all required attributes
- Falls back to HTML insertion if Gutenberg heading not found

## [1.0.9] - 12/10/2025

### Added
- **Enhanced diagnostics** - AJAX response now includes verification data
- Returns debug info showing if image actually exists in database after save
- Console logs show content length before/after
- Browser alert if image not found in DB after insertion
- Helps identify if Gutenberg or another system is interfering

### Improved
- Better debugging output in browser console
- Real-time verification that insertion succeeded
- Clear warning if database verification fails

## [1.0.8] - 12/10/2025

### Fixed
- **CRITICAL**: Fixed Gutenberg auto-save conflict preventing image insertions
- Temporarily disable revision hook during insertion to prevent conflicts
- Delete Gutenberg auto-save drafts after insertion
- Force cache flush to ensure Gutenberg loads updated content
- Added cache clearing for post_meta to prevent stale data

### Changed
- Enhanced cache clearing to include wp_cache_delete for posts and post_meta
- Force wp_cache_flush() after insertions
- Remove and re-add wp_save_post_revision hook during update

### Technical
- Gutenberg auto-save was overwriting manual insertions
- Now deletes auto-save revisions after successful insertion
- Forces complete cache clear to ensure reload shows new content

## [1.0.7] - 12/10/2025

### Fixed
- **CRITICAL**: Added comprehensive error logging to debug insertion failures
- Added verification checks for post and image existence before insertion
- Enhanced error messages to identify specific failure points
- Logs now track content length before/after insertion

### Added
- Detailed error_log() calls throughout insertion process
- Post existence verification
- Image attachment type verification
- Content length verification after update
- Warning if content doesn't increase after insertion

### Debugging
- Check wp-content/debug.log for detailed insertion logs
- All operations now logged with "SIM:" prefix
- Helps identify why images aren't being inserted

## [1.0.6] - 12/10/2025

### Changed
- **Enhanced scoring algorithm** with intentional title bonus (+10 points)
- Titles that are manually set (different from filename) now get priority boost
- Removed caption scoring (rarely used, inconsistent data)
- Simplified to 3-field scoring: Filename (100), Title (90+10), Alt (85)

### Improved
- Better detection of intentionally-set titles vs auto-generated
- Rewards users who take time to properly title their images
- More accurate scoring for well-maintained media libraries
- Cleaner algorithm without unused caption field

### Technical
- Added `$title_is_intentional` detection
- Compares normalized title vs normalized filename
- +10 bonus when title is manually set AND matches
- +5 additional bonus for perfect title matches with all keywords

## [1.0.5] - 12/10/2025

### Fixed
- **CRITICAL**: Added auto-reload after image insertions - page now refreshes to show inserted images
- Fixed modal staying open indefinitely after timer expires
- Single image insertion now reloads page after 2 seconds
- Bulk insertion shows 10-second countdown with auto-reload

### Added
- "Reload Now" button for immediate page refresh
- "Cancel Auto-Reload" button for users who want to stay
- Undo still available during countdown period
- Console logging for debugging insertion issues
- Clear user feedback about reload behavior

### Improved
- Better UX - user doesn't have to manually reload
- Dual timer display (undo + reload countdown)
- Option to close without reloading if needed
- Undo also reloads page after 2 seconds

## [1.0.4] - 12/10/2025

### Fixed
- **CRITICAL**: Fixed scoring algorithm to properly prioritize exact matches
- Images with exact keyword matches now score higher than partial matches
- Added phrase matching bonus for exact heading text in filename/title
- Added penalty for overly verbose filenames (dilution factor)
- Fixed issue where "types-of-black-widow-spiders.jpg" scored higher than "western-black-widow.jpg" for heading "Western Black Widow"

### Changed
- Completely rewrote `calculate_match_score()` algorithm for better accuracy
- Now uses word-by-word matching with completion percentage
- Exact phrase matches get 100% score for filename, 98% for title
- Verbose filenames (3+ extra words) get 15% penalty
- Weighted scoring system: filename (1.0), title (0.9), alt (0.85), caption (0.3)

### Improved
- Better handling of multi-word headings
- More accurate relevance scoring
- Prioritizes specificity over generality
- Better differentiation between similar images

## [1.0.3] - 12/10/2025

### Fixed
- **CRITICAL**: Added button to WordPress Admin Bar (top black bar) for 100% reliability
- Enhanced Gutenberg toolbar detection with multiple selectors and retry logic
- Added console logging for debugging Gutenberg integration
- Improved button positioning and styling

### Added
- Admin Bar button integration (always visible, works in all editors)
- Retry mechanism for Gutenberg toolbar detection (10 attempts)
- Multiple Gutenberg selector support for different WordPress versions
- CSS styling for Admin Bar button

## [1.0.2] - 12/10/2025

### Fixed
- **CRITICAL**: Added Gutenberg (Block Editor) support - button now appears in toolbar
- Fixed button not appearing on post edit screen for Gutenberg users
- Added `enqueue_block_editor_assets` hook for proper Gutenberg integration
- Modal now properly renders in admin footer for both editors

### Changed
- Updated `class-sim-admin.php` to support both Classic Editor and Gutenberg
- Enhanced JavaScript to handle Gutenberg button clicks
- Improved button positioning and visibility in both editors

## [1.0.1] - 12/10/2025

### Fixed
- **CRITICAL**: Updated image matching priority to correctly prioritize Title (90 points) and Alt Text (85 points) over Caption (30 points)
- Filename now correctly scores 100 points for keyword matches (increased from 75 points)
- AI matching now includes all metadata fields (Filename, Title, Alt, Caption) in proper priority order

### Changed
- Updated scoring algorithm in `class-sim-matcher.php` to reflect real-world metadata usage
- Updated AI candidate list in `class-sim-ai.php` to send metadata in priority order

## [1.0.0] - 12/10/2025

### Added
- Initial plugin release
- Phase 1: Foundation
  - Complete plugin structure and file organization
  - Database tables for matches and queue management
  - Activation, deactivation, and uninstall hooks with data cleanup
  - Security framework with nonce verification and capability checks
- Phase 2: Matching Engine
  - Heading extraction from post content (H2-H6)
  - Keyword-based matching algorithm with confidence scoring
  - Smart hierarchy logic to filter redundant subheadings
  - Three hierarchy modes: All Headings, Primary Only, and Smart Hierarchy
  - Keyword overlap calculation for parent-child heading relationships
- Phase 4: Image Insertion
  - Gutenberg block format support for inserted images
  - Image insertion after headings with proper positioning
  - Caption and alt text preservation
  - Undo functionality with 10-second timeout
  - Post content backup and restoration
- Core Features
  - Post editor modal with 6 states (Loading, Results, Insert, Bulk, Success, Error)
  - AJAX handlers for real-time processing
  - Individual and bulk image insertion
  - Image preview (200x200px) with metadata display
  - Confidence score visualization with color coding
  - Real-time progress indicators
- Cache Management
  - Comprehensive cache clearing for major plugins
  - Support for WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache
  - Support for LiteSpeed Cache, Autoptimize, Comet Cache, WP-Optimize
  - Media library caching with configurable expiration
  - Match results caching (1 hour default)
- AI Integration
  - Claude API integration for AI-powered matching
  - Rate limiting (50 calls/hour, 500 calls/day)
  - Automatic fallback to keyword mode on API errors
  - AI reasoning display in match results
  - Cost tracking and daily spending limits
- Settings Page
  - Match mode selection (Keyword/AI)
  - Confidence threshold configuration
  - Hierarchy mode options
  - Claude API key management
  - Daily spending limits and batch size controls
  - Cache duration settings
  - Data management options
- Admin Interface
  - Post editor button integration
  - Modal interface with responsive design
  - Bulk processing page (placeholder for Phase 7)
  - Settings page with comprehensive options
- Security & Performance
  - Nonce verification for all AJAX requests
  - Capability checks (edit_posts, manage_options)
  - Input sanitization and output escaping
  - SQL injection prevention with $wpdb->prepare()
  - Database indexing for optimized queries
  - Lazy loading of admin assets
  - Transient-based caching

### Technical Details
- Minimum WordPress version: 6.0
- Minimum PHP version: 7.4
- Database tables: wp_sim_matches, wp_sim_queue
- Dynamic table prefix support (never hardcoded)
- Comprehensive error handling and logging
- WordPress coding standards compliance

### Notes
- Phase 3 (Modal UI) - Fully implemented
- Phase 5 (Cache Compatibility) - Fully implemented
- Phase 6 (AI Integration) - Core functionality complete
- Phase 7 (Bulk Processing) - Placeholder created, full implementation pending
- Phase 8 (Advanced Settings) - Core settings implemented
- Ready for testing and production use with keyword matching
- AI mode requires Claude API key from Anthropic

