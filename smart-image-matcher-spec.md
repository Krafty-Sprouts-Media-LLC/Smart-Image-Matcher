# Smart Image Matcher - WordPress Plugin Specification

**Developer:** Krafty Sprouts Media, LLC  
**Website:** https://kraftysprouts.com  
**Version:** 1.0.0  
**Last Updated:** October 12, 2025

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Core Features](#core-features)
3. [Technical Architecture](#technical-architecture)
4. [Matching Algorithm](#matching-algorithm)
5. [User Interface](#user-interface)
6. [Cache Compatibility](#cache-compatibility)
7. [API Rate Limiting](#api-rate-limiting)
8. [Activation & Uninstall](#activation-uninstall)
9. [Security & Performance](#security-performance)
10. [Development Timeline](#development-timeline)
11. [Quick Start Guide](#quick-start-guide)

---

## Executive Summary

Smart Image Matcher is a WordPress plugin that automatically scans the media library and intelligently attaches relevant images to headings within posts and pages. The plugin offers two modes: a fast keyword-based matching system and an AI-powered matching system using the Claude API for enhanced accuracy.

**Key Features:**
- Automatic image-to-heading matching
- Two modes: Keyword (fast) and AI (accurate)
- Modal interface for single post processing
- Bulk processing for multiple posts
- Smart hierarchy handling (H2, H3, H4)
- Cache compatibility with major plugins
- API rate limiting and cost controls
- Complete uninstall cleanup

---

## Core Features

### 1. Two Matching Modes

#### Mode A: Keyword Matching (Non-AI)
- Extract headings (H2-H6) from post content
- Normalize heading text
- Search media library for matching filenames
- Confidence scoring based on keyword overlap
- Processing time: 3-7 seconds per article

#### Mode B: AI-Powered Matching (Claude API)
- All features from Mode A, plus:
- Semantic understanding of content
- Visual verification capability
- Relevance scores with reasoning
- Handles fuzzy matching
- Processing time: 11-27 seconds per article

### 2. Two Workflow Options

#### Workflow 1: On-Demand (Single Post)
- Button in post editor
- Opens modal interface
- Review and approve matches
- Individual or bulk insert
- Auto-save after insertion

#### Workflow 2: Bulk Processing
- Admin page for batch operations
- Select posts by filters
- Background processing
- Review queue for approval
- Comprehensive reporting

---

## Technical Architecture

### Plugin Structure

```
smart-image-matcher/
├── smart-image-matcher.php          # Main plugin file
├── uninstall.php                    # Complete cleanup
├── includes/
│   ├── class-sim-core.php           # Core functionality
│   ├── class-sim-matcher.php        # Matching engine
│   ├── class-sim-ai.php             # Claude API
│   ├── class-sim-admin.php          # Admin interface
│   ├── class-sim-ajax.php           # AJAX handlers
│   ├── class-sim-bulk.php           # Bulk processing
│   ├── class-sim-settings.php       # Settings
│   └── class-sim-cache.php          # Cache management
├── admin/
│   ├── css/sim-admin.css
│   ├── js/sim-editor.js
│   ├── js/sim-bulk.js
│   └── views/
│       ├── settings-page.php
│       ├── bulk-processor.php
│       └── review-queue.php
└── readme.txt
```

### Database Schema

**{$wpdb->prefix}sim_matches**
```sql
CREATE TABLE {$wpdb->prefix}sim_matches (
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

**{$wpdb->prefix}sim_queue**
```sql
CREATE TABLE {$wpdb->prefix}sim_queue (
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

**IMPORTANT:** Always use `$wpdb->prefix` dynamically. Never hardcode 'wp_'.

---

## Matching Algorithm

### Heading Hierarchy Handling

**Three Processing Strategies:**

1. **All Headings** - Process every H2-H6 independently
2. **Primary Only** - Process only H2 headings
3. **Smart Hierarchy** (Recommended) - Process H2 always, H3+ only if semantically distinct

**Smart Hierarchy Logic:**
- Always process H2 headings
- Skip H3+ if keywords overlap >70% with parent H2
- Skip if parent already has image
- Skip if too close to previous image (minimum spacing)
- Process if introduces new subject/entity

### Keyword Matching Algorithm

```
1. Normalize heading text (lowercase, remove special chars)
2. Extract significant keywords
3. For each image in media library:
   - Extract keywords from filename, alt text, caption
   - Calculate overlap score:
     * Exact filename match: 100 points
     * Partial filename match: 75 points
     * Alt text match: 50 points
     * Caption match: 25 points
4. Rank images by score
5. Return top matches with confidence ≥ threshold (default 70%)
```

### AI Matching (Claude API)

```
1. Prepare request with heading text and candidate images
2. Send to Claude API with matching prompt
3. Claude returns JSON with relevance scores (0-100)
4. Parse response and store AI reasoning
5. Return ranked matches
```

**Prompt Template:**
```
Given heading: "{heading_text}"
Rank these images by relevance (0-100):
{image_list}

Respond ONLY with valid JSON:
{
  "matches": [
    {
      "image_id": 123,
      "relevance_score": 95,
      "reasoning": "Exact match...",
      "confidence": "high"
    }
  ]
}
```

---

## User Interface

### 1. Post Editor Modal

**6 Modal States:**

#### State 1: Loading
```
┌────────────────────────────────┐
│ Smart Image Matcher  [✕ Close]│
├────────────────────────────────┤
│ Analyzing content...           │
│ ▓▓▓▓▓▓░░░░ 50%                │
│ ⏳ Found 12 headings           │
│ ⏳ Searching 450 images        │
└────────────────────────────────┘
```

#### State 2: Results Display
```
┌──────────────────────────────────────┐
│ Smart Image Matcher        [✕ Close]│
├──────────────────────────────────────┤
│ Found 11 matches for 12 headings     │
│                                      │
│ ✓ H2: Black Swallowtail             │
│ ┌────────┐                           │
│ │[Image  │ Confidence: 95%           │
│ │200x200]│ Filename: black-swa...    │
│ └────────┘ ☑ Selected                │
│ [View Full ↗] [Insert Now]           │
│                                      │
│ ✗ H2: Feeding Behavior               │
│ ⚠ No matching image found            │
│                                      │
│ [Cancel]      [Insert All Selected]  │
└──────────────────────────────────────┘
```

#### State 3: Individual Insert
- Shows "✓ Image inserted successfully!"
- Modal stays open
- Displays [Undo Insert] button

#### State 4: Bulk Processing
```
┌────────────────────────────────┐
│ Inserting Images...  [✕ Close]│
├────────────────────────────────┤
│ ▓▓▓▓▓▓▓▓░░░░ 8/11             │
│                                │
│ ✓ Black Swallowtail - Inserted│
│ ✓ Monarch - Inserted           │
│ ⏳ Inserting Io Moth...        │
│                                │
│ Saving draft...                │
└────────────────────────────────┘
```

#### State 5: Success
```
┌────────────────────────────────┐
│ Success! ✓           [✕ Close]│
├────────────────────────────────┤
│ ✓ Inserted 11 images           │
│ ✓ Draft saved automatically    │
│                                │
│ [Undo All Insertions]          │
│ ← Available for 10s... ⏱ 8s   │
│                                │
│ [View Post] [Close]            │
└────────────────────────────────┘
```

#### State 6: Error
```
┌────────────────────────────────┐
│ Error              [✕ Close]   │
├────────────────────────────────┤
│ ⚠ Processing failed            │
│                                │
│ Error: API connection failed   │
│                                │
│ [Try Again] [Switch to Keyword]│
└────────────────────────────────┘
```

**Key Features:**
- Image preview: 200x200px
- "View Full Image" opens in new tab
- Individual and bulk insert options
- 10-second undo timer
- Auto-save after insertion

### 2. Bulk Processing Interface

Full admin page at Tools > Smart Image Matcher with 4 steps:

1. **Select Posts** - Filters, preview, post count
2. **Configure** - Mode, confidence, options, cost estimate
3. **Processing** - Progress bar, activity log, stats
4. **Review Queue** - Table view, bulk actions, summary

---

## Cache Compatibility

### Supported Cache Systems

**Page Caching:**
- WP Rocket
- W3 Total Cache
- WP Super Cache
- WP Fastest Cache
- LiteSpeed Cache
- Autoptimize
- Comet Cache
- WP-Optimize

**Object Caching:**
- Redis
- Memcached
- APCu

### Cache Clearing Implementation

```php
function sim_clear_all_caches($post_id) {
    // WordPress internal
    clean_post_cache($post_id);
    wp_cache_delete($post_id, 'posts');
    
    // Plugin transients
    delete_transient('sim_matches_' . $post_id);
    delete_transient('sim_media_library_cache');
    
    // WP Rocket
    if (function_exists('rocket_clean_post')) {
        rocket_clean_post($post_id);
    }
    
    // W3 Total Cache
    if (function_exists('w3tc_flush_post')) {
        w3tc_flush_post($post_id);
    }
    
    // [Other cache plugins...]
}
```

### Caching Strategy

**Cache with expiration:**
- Media library query: 24 hours
- Match results: 1 hour
- Settings: permanent

**Never cache:**
- Current post content
- AJAX responses
- API responses

---

## API Rate Limiting & Cost Management

### Pricing Context (January 2025)

**Claude Sonnet 4:**
- Input: $3 per million tokens
- Output: $15 per million tokens

**Average Costs:**
- Text-only: ~$0.0045 per request
- With visuals: ~$0.011 per request
- 100 posts: ~$0.45-$1.10
- 1000 posts: ~$4.50-$11.00

### Rate Limiting

```php
// Hard limits
define('SIM_MAX_API_CALLS_PER_HOUR', 50);
define('SIM_MAX_API_CALLS_PER_DAY', 500);

// Check before each API call
function sim_check_rate_limit() {
    $hour_calls = get_transient('sim_api_calls_hour');
    
    if ($hour_calls >= SIM_MAX_API_CALLS_PER_HOUR) {
        return new WP_Error('rate_limit', 'Hourly limit reached');
    }
    
    return true;
}
```

### Cost Controls

**User Settings:**
- Daily spending limit
- Batch size limits
- Cost warnings before processing
- Automatic fallback to keyword mode
- Email notifications

**Pre-Processing Cost Estimate:**
```
┌─────────────────────────────────┐
│ Cost Estimate                   │
├─────────────────────────────────┤
│ Posts: 47                       │
│ Estimated cost: $0.52 - $0.75   │
│ Daily budget: $7.70 remaining   │
│                                 │
│ ☑ I understand the cost         │
│ [Continue]                      │
└─────────────────────────────────┘
```

---

## Activation & Uninstall

### Activation Hook

```php
register_activation_hook(__FILE__, 'sim_activate_plugin');

function sim_activate_plugin() {
    // Check requirements
    if (version_compare(get_bloginfo('version'), '6.0', '<')) {
        wp_die('Requires WordPress 6.0+');
    }
    
    // Create database tables
    sim_create_database_tables();
    
    // Set default options
    sim_set_default_options();
    
    // Schedule cron jobs
    wp_schedule_event(time(), 'daily', 'sim_daily_cleanup');
}
```

### Deactivation Hook

```php
register_deactivation_hook(__FILE__, 'sim_deactivate_plugin');

function sim_deactivate_plugin() {
    // Clear scheduled crons
    wp_clear_scheduled_hook('sim_daily_cleanup');
    
    // Clear all transients
    sim_clear_all_transients();
    
    // NOTE: Do NOT delete tables/options here
    // That only happens on uninstall
}
```

### Complete Uninstall (uninstall.php)

```php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function sim_uninstall_plugin() {
    global $wpdb;
    
    // Check user preference
    $delete_data = get_option('sim_delete_on_uninstall', 1);
    
    if (!$delete_data) {
        // User wants to keep data
        return;
    }
    
    // 1. Drop database tables
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sim_matches");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sim_queue");
    
    // 2. Delete all options
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sim_%'");
    
    // 3. Delete all transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sim_%'");
    
    // 4. Clear cron jobs
    wp_clear_scheduled_hook('sim_daily_cleanup');
    
    // 5. Delete uploaded files/directories
    $upload_dir = wp_upload_dir();
    $sim_dir = $upload_dir['basedir'] . '/smart-image-matcher';
    sim_recursive_delete($sim_dir);
    
    // 6. Clear all caches
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
}

sim_uninstall_plugin();
```

### Data Export Feature

Users can export all data before uninstalling:
- Settings
- Match history
- API usage statistics
- Download as JSON

---

## Security & Performance

### Security Measures

1. **Nonce verification** for all AJAX requests
2. **Capability checks** (edit_posts, manage_options)
3. **Input sanitization** and output escaping
4. **API key encryption** using WordPress salts
5. **Rate limiting** to prevent abuse
6. **SQL injection prevention** using $wpdb->prepare()

### Performance Optimizations

1. **Caching** with WordPress Transients
2. **Database indexing** on frequently queried columns
3. **Lazy loading** of admin assets
4. **Background processing** for bulk operations
5. **API request batching** when possible

---

## Development Timeline

### Phase 1: Foundation (Week 1-2)
- Plugin structure
- Database tables
- Activation/deactivation/uninstall hooks
- Security setup

### Phase 2: Matching Engine (Week 2-3)
- Heading parser
- Keyword extraction
- Confidence scoring
- Hierarchy logic

### Phase 3: Modal UI (Week 3-4)
- All 6 modal states
- AJAX handlers
- Image insertion
- Undo functionality

### Phase 4: Image Insertion (Week 4)
- Gutenberg block format
- Classic editor support
- Caption handling
- Position control

### Phase 5: Cache Compatibility (Week 5)
- Clear all major cache plugins
- Transient management
- Cache detection

### Phase 6: AI Integration (Week 5-6)
- Claude API client
- Error handling
- Token tracking
- Cost management

### Phase 7: Bulk Processing (Week 6-7)
- Admin page
- Queue system
- Background processing
- Review queue

### Phase 8: Settings (Week 7)
- All settings tabs
- Validation
- Export/import

### Phase 9: Testing (Week 8)
- Unit tests
- Integration tests
- Security audit
- Performance testing

### Phase 10: Documentation (Week 8-9)
- User manual
- Video tutorials
- Developer docs

### Phase 11: Launch (Week 9-10)
- Beta testing
- Bug fixes
- Final release

**Total: 9-10 weeks**

---

## Quick Start Guide for Cursor AI

### Build Order Priority:

1. **Phase 1** (Foundation) - Get structure working
2. **Phase 2** (Matching Engine) - CRITICAL
3. **Phase 4** (Image Insertion) - Make it work
4. **Phase 3** (Modal UI) - Make it user-friendly
5. **Phase 5** (Cache) - Prevent issues
6. **Phase 6** (AI) - Add intelligence
7. **Phase 7-11** - Scale and polish

### Key Requirements:

- Use `$wpdb->prefix` (NEVER hardcode 'wp_')
- WordPress native functions only (no external libraries)
- Comprehensive cache clearing
- All AJAX needs nonces
- Modal has 6 states
- Image preview: 200x200px
- Auto-save after insertion
- Rate limiting for AI

### Testing Checklist:

- [ ] Works with major cache plugins
- [ ] Matches "Caterpillars" article correctly
- [ ] All 6 modal states work
- [ ] Individual and bulk insert work
- [ ] 10-second undo works
- [ ] Gutenberg and Classic Editor support
- [ ] Bulk processes 50+ posts
- [ ] No security vulnerabilities

### When Stuck:

- Check Cache Compatibility section
- Check API Rate Limiting section
- Check Modal UI specifications
- Check Database Schema section

**Remember:**
- For Krafty Sprouts Media, LLC
- Professional quality required
- Must work at scale
- Security is non-negotiable

---

**END OF SPECIFICATION**

*Version 1.0.0 - Complete and ready for development*