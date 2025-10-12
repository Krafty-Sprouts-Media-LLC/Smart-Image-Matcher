# Changelog

All notable changes to Smart Image Matcher will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

