# Debugging Image Insertion Issues

**Version:** 1.0.8  
**Date:** 12/10/2025

---

## 🐛 If Images Still Don't Insert

### Step 1: Enable WordPress Debug Mode

Edit `wp-config.php` and add/change these lines:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

This creates a `debug.log` file at: `wp-content/debug.log`

---

### Step 2: Test Insertion

1. Click "Smart Image Matcher" button in admin bar
2. Select an image
3. Click "Insert Now" or "Insert All Selected"
4. Wait for the page to reload

---

### Step 3: Check Debug Log

Open `wp-content/debug.log` and look for lines starting with **"SIM:"**

**What to look for:**

✅ **Success Pattern:**
```
SIM: Insert image request - Post ID: 123, Image ID: 456, Position: 789
SIM: Original content length: 1500
SIM: Found 3 headings
SIM: Looking for heading at position: 789
SIM: Checking heading at position: 789
SIM: Found matching heading! End position: 850
SIM: Image block created, length: 250
SIM: New content length: 1750
SIM: wp_update_post succeeded, post ID: 123
SIM: Deleted Gutenberg autosave
SIM: Verified updated content length: 1750
SIM: Image inserted successfully
```

❌ **Failure Patterns:**

**Pattern 1: Heading not found**
```
SIM: Found 3 headings
SIM: Looking for heading at position: 789
SIM: Checking heading at position: 100
SIM: Checking heading at position: 500
SIM: Checking heading at position: 900
SIM: Heading not found at position 789
```
→ **Problem:** Content changed between analysis and insertion

**Pattern 2: Content didn't increase**
```
SIM: Original content length: 1500
SIM: New content length: 1750
SIM: wp_update_post succeeded
SIM: Verified updated content length: 1500  ← PROBLEM!
SIM: WARNING - Content length did not increase!
```
→ **Problem:** Gutenberg auto-save overwriting changes

**Pattern 3: wp_update_post failed**
```
SIM: wp_update_post failed: [error message]
```
→ **Problem:** WordPress update error

---

### Step 4: Check Browser Console

Press **F12** → **Console** tab

Look for:
```
SIM: Inserting single image {imageId: 456, headingPosition: 789, postId: 123}
SIM: Insert response {success: true, data: {...}}
```

Or errors:
```
SIM: Insert failed [error details]
SIM: AJAX error {xhr: ..., status: ..., error: ...}
```

---

### Step 5: Common Issues & Solutions

#### Issue 1: Gutenberg Auto-Save Conflict
**Symptom:** "Content length did not increase" in debug.log  
**Solution:** v1.0.8 fixes this - deactivate/reactivate plugin

#### Issue 2: Post Content Changed
**Symptom:** "Heading not found at specified position"  
**Solution:** Don't edit the post between analysis and insertion

#### Issue 3: Permission Denied
**Symptom:** "Permission denied" in AJAX response  
**Solution:** Check user capabilities - must have 'edit_posts'

#### Issue 4: Image Not Found
**Symptom:** "Image not found - ID: X"  
**Solution:** Verify image exists in Media Library

#### Issue 5: Cache Plugin Interference
**Symptom:** Changes save but don't appear on frontend  
**Solution:** Manually clear cache plugin (WP Rocket, W3TC, etc.)

---

### Step 6: Manual Test

Try inserting an image manually to verify Gutenberg works:

1. Add a heading: `## Test Heading`
2. Add an image block manually below it
3. Save the post
4. If manual insertion works but plugin doesn't → it's a plugin issue
5. If manual insertion fails → it's a Gutenberg/WordPress issue

---

### Step 7: Check WordPress Health

Go to **Tools → Site Health**

Check for:
- WordPress version 6.0+
- PHP 7.4+
- No fatal errors
- Sufficient memory

---

### Step 8: Disable Conflicting Plugins

Temporarily deactivate these types of plugins:
- Auto-save managers
- Content filters
- SEO plugins that modify content
- Translation plugins
- Page builders

Test if Smart Image Matcher works with them disabled.

---

### Step 9: Test with Classic Editor

Install "Classic Editor" plugin and test if insertions work there.

If it works in Classic Editor but not Gutenberg → confirms Gutenberg-specific issue.

---

### Step 10: Share Debug Info

If still not working, share:

1. **Debug log excerpt** (lines with "SIM:")
2. **Browser console log** (F12 → Console)
3. **WordPress version**
4. **Active theme name**
5. **List of active plugins**
6. **PHP version**

---

## 📝 Expected Behavior (v1.0.8)

1. User clicks "Insert All"
2. AJAX saves images to database
3. Gutenberg auto-save is deleted
4. All caches cleared
5. Page reloads after 10 seconds
6. Updated content loads from database
7. Images visible in editor and on frontend

---

## 🔧 Version 1.0.8 Fixes

- ✅ Deletes Gutenberg auto-save after insertion
- ✅ Temporarily disables revision hook
- ✅ Forces complete cache flush
- ✅ Clears post and post_meta caches
- ✅ Verifies content length increased

---

**If images still don't insert after v1.0.8, the debug log will tell us exactly why!**

