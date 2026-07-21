# Smart Image Matcher - Version History

## Version 1.0.1 (12/10/2025) - Current Version ✅

### Critical Metadata Priority Fix

**Problem Identified:**
Version 1.0.0 had incorrect scoring priorities that undervalued Title and Alt Text fields, which are almost always filled in properly maintained WordPress media libraries.

**Changes Made:**

#### Scoring Algorithm Update
```php
// Version 1.0.0 (INCORRECT)
Filename:    75 points
Alt Text:    50 points  
Title:       30 points  ← Too low!
Caption:     25 points

// Version 1.0.1 (CORRECT)
Filename:   100 points  ← Primary field, always filled
Title:       90 points  ← WordPress title, almost always filled
Alt Text:    85 points  ← SEO critical, almost always filled
Caption:     30 points  ← Often empty
```

#### Files Modified:
1. **smart-image-matcher.php**
   - Version updated from 1.0.0 to 1.0.1
   - SIM_VERSION constant updated

2. **includes/class-sim-matcher.php**
   - Version header updated to 1.0.1
   - `calculate_match_score()` method updated with correct priorities
   - Added scoring priority documentation in file header

3. **includes/class-sim-ai.php**
   - Version header updated to 1.0.1
   - `call_claude_api()` updated to send metadata in correct priority order
   - AI prompt now includes: Filename, Title, Alt, Caption (in order)

4. **CHANGELOG.md**
   - Added version 1.0.1 section with detailed changes
   - Separated from 1.0.0 initial release

5. **readme.txt**
   - Stable tag updated to 1.0.1
   - Changelog entry added for 1.0.1
   - Upgrade notice added

6. **README.md**
   - Version updated to 1.0.1

7. **BUILD_SUMMARY.md**
   - Version updated to 1.0.1
   - Version history section added

### Impact:
- **High Priority Images**: Title and Alt Text now receive proper scoring
- **Better Matches**: More accurate matches for well-maintained media libraries
- **AI Accuracy**: Claude API receives metadata in optimal order
- **Backward Compatible**: No database changes, no breaking changes

### Upgrade Instructions:
Simply replace plugin files or use WordPress update mechanism. No configuration changes needed.

---

## Version 1.0.0 (12/10/2025) - Initial Release

### Features Implemented:

#### Phase 1: Foundation ✅
- Plugin structure and file organization
- Database tables (wp_sim_matches, wp_sim_queue)
- Activation, deactivation, uninstall hooks
- Security framework (nonces, capability checks, sanitization)

#### Phase 2: Matching Engine ✅
- Heading extraction (H2-H6) from post content
- Keyword-based matching algorithm
- Smart hierarchy logic with 3 modes
- Confidence scoring system
- ⚠️ Note: Scoring priorities were corrected in v1.0.1

#### Phase 3: Modal UI ✅
- 6 modal states (Loading, Results, Insert, Bulk, Success, Error)
- Image preview (200x200px)
- Individual and bulk insertion
- Progress tracking

#### Phase 4: Image Insertion ✅
- Gutenberg block format support
- Caption and alt text preservation
- 10-second undo functionality
- Auto-save after insertion

#### Phase 5: Cache Compatibility ✅
- WP Rocket, W3 Total Cache, WP Super Cache
- WP Fastest Cache, LiteSpeed Cache
- Autoptimize, Comet Cache, WP-Optimize
- Media library caching (24 hours)
- Match results caching (1 hour)

#### Phase 6: AI Integration ✅
- Claude API client implementation
- Rate limiting (50/hour, 500/day)
- Cost tracking and daily limits
- Automatic fallback to keyword mode
- AI reasoning display
- ⚠️ Note: Metadata order was corrected in v1.0.1

#### Additional Features ✅
- Settings page with comprehensive options
- AJAX handlers for real-time processing
- Admin interface with editor button
- SQL injection prevention
- Database indexing for performance

### Files Created:
- smart-image-matcher.php (165 lines)
- uninstall.php (71 lines)
- 8 class files in includes/ (~1,200 lines)
- Admin CSS and JavaScript (~300 lines)
- View templates (~250 lines)
- Documentation files

**Total:** ~1,985 lines of code

### Known Issues in v1.0.0:
- ⚠️ Incorrect scoring priorities (Fixed in v1.0.1)
- Bulk processing UI placeholder only (Phase 7 pending)

---

## Release Notes

### Version 1.0.1 (Recommended) ✅
**Status:** Production Ready  
**Changes:** Critical scoring fix  
**Upgrade:** Recommended for all users  
**Breaking Changes:** None

### Version 1.0.0
**Status:** Superseded by v1.0.1  
**Issue:** Incorrect metadata scoring priorities  
**Recommendation:** Upgrade to v1.0.1

---

## Upgrade Path

### From 1.0.0 to 1.0.1
1. Deactivate plugin (optional, but recommended)
2. Replace all plugin files
3. Reactivate plugin
4. No configuration changes needed
5. Existing matches will use new scoring on next run

**Data Safety:** No data loss, no database changes

---

## Future Roadmap

### Version 1.1.0 (Planned)
- Phase 7: Full bulk processing UI
- Background processing with progress tracking
- Review queue management
- Batch operations

### Version 1.2.0 (Planned)
- Phase 8: Advanced settings
- Data export/import
- Custom post type support
- Additional metadata fields

### Version 2.0.0 (Planned)
- Visual AI matching (image analysis)
- Machine learning improvements
- Performance optimizations
- Multi-language support

---

**Current Version:** 1.0.1  
**Release Date:** 12/10/2025  
**Status:** ✅ Production Ready  
**Next Update:** TBD (Phase 7 - Bulk Processing)

