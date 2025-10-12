# Smart Image Matcher - WordPress Plugin

**Developer:** Krafty Sprouts Media, LLC  
**Website:** https://kraftysprouts.com  
**Version:** 1.0.2  
**Last Updated:** 12/10/2025

## Overview

Smart Image Matcher is a WordPress plugin that automatically scans the media library and intelligently attaches relevant images to headings within posts and pages. The plugin offers two modes: a fast keyword-based matching system and an AI-powered matching system using the Claude API for enhanced accuracy.

## Features

- ✅ **Automatic Image Matching** - Keyword-based and AI-powered
- ✅ **Post Editor Modal** - 6 states with progress tracking
- ✅ **Smart Hierarchy** - Intelligent heading filtering
- ✅ **Image Insertion** - Gutenberg block format support
- ✅ **Cache Compatibility** - Support for all major cache plugins
- ✅ **Undo Functionality** - 10-second timeout for bulk insertions
- ✅ **API Rate Limiting** - Cost controls for AI mode
- ✅ **Settings Page** - Comprehensive configuration options

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- (Optional) Claude API key for AI mode

## Installation

1. Upload the `smart-image-matcher` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Configure settings at Settings → Smart Image Matcher
4. (Optional) Add Claude API key for AI-powered matching

## Usage

### Single Post Processing

1. Edit any post or page
2. Click "Smart Image Matcher" button below the title
3. Wait for analysis to complete
4. Review suggested matches
5. Insert images individually or all at once
6. Use "Undo" within 10 seconds if needed

### Bulk Processing

1. Go to Tools → Smart Image Matcher
2. Select posts to process
3. Configure matching options
4. Review and approve matches

(Note: Bulk processing UI will be fully implemented in Phase 7)

### Settings

- **Match Mode**: Choose between Keyword (fast) or AI (accurate)
- **Confidence Threshold**: Minimum score for matches (0-100)
- **Hierarchy Mode**: How to handle subheadings
- **API Configuration**: Claude API key and cost limits
- **Cache Settings**: Media library and match result caching

## File Structure

```
smart-image-matcher/
├── smart-image-matcher.php          # Main plugin file
├── uninstall.php                    # Cleanup on uninstall
├── readme.txt                       # WordPress.org readme
├── README.md                        # Developer documentation
├── CHANGELOG.md                     # Version history
├── includes/
│   ├── class-sim-core.php           # Core initialization
│   ├── class-sim-matcher.php        # Matching engine
│   ├── class-sim-ai.php             # Claude API integration
│   ├── class-sim-admin.php          # Admin interface
│   ├── class-sim-ajax.php           # AJAX handlers
│   ├── class-sim-bulk.php           # Bulk processing
│   ├── class-sim-settings.php       # Settings management
│   └── class-sim-cache.php          # Cache compatibility
├── admin/
│   ├── css/
│   │   └── sim-admin.css            # Admin styles
│   ├── js/
│   │   ├── sim-editor.js            # Modal interface
│   │   └── sim-bulk.js              # Bulk processing
│   └── views/
│       ├── settings-page.php        # Settings UI
│       ├── bulk-processor.php       # Bulk UI
│       └── review-queue.php         # (Coming in Phase 7)
```

## Database Tables

### wp_sim_matches

Stores match results for review and approval.

```sql
CREATE TABLE wp_sim_matches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NOT NULL,
  heading_text VARCHAR(255) NOT NULL,
  heading_tag VARCHAR(10) NOT NULL,
  heading_position INT NOT NULL,
  image_id BIGINT UNSIGNED NOT NULL,
  confidence_score INT NOT NULL,
  match_method VARCHAR(20) NOT NULL,
  ai_reasoning TEXT,
  status VARCHAR(20) DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  INDEX post_id_idx (post_id),
  INDEX status_idx (status)
);
```

### wp_sim_queue

Manages bulk processing queue.

```sql
CREATE TABLE wp_sim_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) DEFAULT 'queued',
  priority INT DEFAULT 0,
  attempts INT DEFAULT 0,
  error_message TEXT,
  processed_at DATETIME,
  created_at DATETIME NOT NULL,
  INDEX status_idx (status),
  INDEX post_id_idx (post_id)
);
```

## Matching Algorithm

### Keyword Matching

1. Extract headings (H2-H6) from post content
2. Normalize and extract keywords from heading text
3. Search media library for matching filenames, alt text, captions
4. Calculate confidence score:
   - Exact filename match: 100 points
   - Partial filename match: 75 points
   - Alt text match: 50 points
   - Caption match: 25 points
5. Return matches above confidence threshold

### AI Matching (Claude API)

1. Use keyword matches as candidates
2. Send heading and candidate images to Claude API
3. Receive semantic relevance scores with reasoning
4. Return ranked matches with AI insights

### Smart Hierarchy

- Always process H2 headings
- Skip H3+ if keyword overlap >70% with parent H2
- Skip if parent already has image
- Process if introduces new subject/entity

## Cache Compatibility

Automatically clears cache for these plugins:

- WP Rocket
- W3 Total Cache
- WP Super Cache
- WP Fastest Cache
- LiteSpeed Cache
- Autoptimize
- Comet Cache
- WP-Optimize

## API Rate Limiting

- **Hourly Limit**: 50 API calls
- **Daily Limit**: 500 API calls
- **Cost Estimate**: ~$0.0045-$0.011 per request
- **Auto Fallback**: Switches to keyword mode on limit/error

## Development Status

### ✅ Completed (Phase 1-4)

- [x] Plugin foundation and database
- [x] Matching engine (keyword + AI)
- [x] Image insertion functionality
- [x] Post editor modal interface
- [x] Cache compatibility
- [x] Settings page
- [x] Security and performance

### 🚧 Planned (Phase 7-11)

- [ ] Full bulk processing interface
- [ ] Review queue management
- [ ] Advanced reporting
- [ ] Data export/import
- [ ] Comprehensive testing suite
- [ ] Video tutorials
- [ ] WordPress.org submission

## Testing

### Manual Testing

1. Create a test post with multiple H2 headings
2. Upload images with descriptive filenames
3. Click "Smart Image Matcher" button
4. Verify matches appear correctly
5. Test individual and bulk insertion
6. Test undo functionality

### Cache Testing

1. Enable a caching plugin (WP Rocket, W3TC, etc.)
2. Insert images using the plugin
3. Verify post content updates on frontend
4. Check cache was cleared properly

### AI Testing

1. Add Claude API key in settings
2. Switch to AI mode
3. Process a post
4. Verify AI reasoning appears
5. Check API usage limits

## Support

For support, please contact:
- Email: support@kraftysprouts.com
- Website: https://kraftysprouts.com

## License

GPL v2 or later

## Credits

Developed by Krafty Sprouts Media, LLC

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

