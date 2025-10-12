# Smart Image Matcher - Testing Guide

**Version:** 1.0.2  
**Date:** 12/10/2025

---

## ✅ Version 1.0.2 - Gutenberg Support Fix

### What Was Fixed

**Problem:** Button was not appearing on post edit screen for Gutenberg (Block Editor) users.

**Solution:** Added proper Gutenberg Block Editor support with button in toolbar.

---

## 🧪 Testing Instructions

### Step 1: Deactivate and Reactivate Plugin

1. Go to **Plugins** → **Installed Plugins**
2. Find "Smart Image Matcher"
3. Click **Deactivate**
4. Wait 2 seconds
5. Click **Activate**

This ensures the new code loads properly.

---

### Step 2: Check for the Button

#### If Using Gutenberg (Block Editor):

1. Go to **Posts** → **Add New** (or edit existing post)
2. Look for the **"Smart Image Matcher"** button in the **top toolbar**
3. It should appear next to the save/preview buttons with an image icon 🖼️

#### If Using Classic Editor:

1. Go to **Posts** → **Add New** (or edit existing post)
2. Look for the **"Smart Image Matcher"** button **below the title field**
3. It should have an image icon and text

---

### Step 3: Test the Button

1. Click the **"Smart Image Matcher"** button
2. A modal should appear with:
   - Title: "Smart Image Matcher"
   - Loading state: "Analyzing content..."
   - Progress bar

---

### Step 4: Test with Sample Content

Create a test post with this content:

```
Title: Butterfly Caterpillars Guide

(Post content)
## Black Swallowtail

The black swallowtail caterpillar is beautiful.

## Monarch Butterfly

Monarch caterpillars feed on milkweed.
```

**Before testing:**
1. Upload 2 test images to Media Library:
   - One named: `black-swallowtail-caterpillar.jpg`
   - One named: `monarch-caterpillar.jpg`

**Test the matching:**
1. Click "Smart Image Matcher" button
2. Wait for analysis (3-5 seconds)
3. Should show 2 matches found
4. Confidence scores should be high (80-100%)

---

## 🔍 What to Look For

### ✅ Success Indicators

- **Button Visible:** Button appears in toolbar/below title
- **Modal Opens:** Clicking button shows modal popup
- **Loading Works:** Progress bar animates
- **Matches Found:** Headings are detected and matched with images
- **Preview Shows:** Image thumbnail displays (200x200px)
- **Insert Works:** Clicking "Insert Now" adds image to content
- **Undo Works:** "Undo All" button restores content (10-second timer)

### ❌ Failure Indicators

- **No Button:** Button doesn't appear at all
- **Console Errors:** Check browser console (F12) for JavaScript errors
- **Modal Doesn't Open:** Nothing happens when clicking button
- **No Matches:** Can't find any headings or images
- **Insert Fails:** Images don't get added to content

---

## 🐛 Troubleshooting

### Button Still Not Appearing?

1. **Clear Browser Cache:**
   - Press `Ctrl+Shift+Delete` (Windows) or `Cmd+Shift+Delete` (Mac)
   - Clear cached images and files
   - Reload the page

2. **Check WordPress Version:**
   - Go to **Dashboard** → **Updates**
   - Must be WordPress 6.0 or higher

3. **Check PHP Version:**
   - Go to **Tools** → **Site Health**
   - Must be PHP 7.4 or higher

4. **Check for Plugin Conflicts:**
   - Deactivate all other plugins temporarily
   - Test if button appears
   - Reactivate plugins one by one to find conflict

5. **Check Browser Console:**
   - Press `F12` to open Developer Tools
   - Go to **Console** tab
   - Look for errors (red text)
   - Share screenshot if you see errors

### JavaScript Not Loading?

Check if files exist:
- `wp-content/plugins/Smart-Image-Matcher/admin/js/sim-editor.js`
- `wp-content/plugins/Smart-Image-Matcher/admin/css/sim-admin.css`

### Modal Appears But Shows Error?

1. **Check if headings exist in post content**
   - Add at least one H2 heading: `## Test Heading`

2. **Check if images exist in Media Library**
   - Go to **Media** → **Library**
   - Upload at least one image

3. **Check AJAX is working:**
   - Open browser console (F12)
   - Click button
   - Look for network requests to `/wp-admin/admin-ajax.php`
   - Should see 200 status code

---

## 📊 Expected Behavior by Editor

### Gutenberg (Block Editor)

**Button Location:** Top toolbar, next to save/preview buttons  
**Button Style:** `components-button is-secondary`  
**Loads After:** ~1 second delay (toolbar initialization)

### Classic Editor

**Button Location:** Below title field, above content area  
**Button Style:** `button button-secondary`  
**Loads:** Immediately on page load

---

## 🎯 Quick Test Checklist

- [ ] Plugin activated successfully
- [ ] Button visible in post editor
- [ ] Button clickable (no errors)
- [ ] Modal opens when clicked
- [ ] Loading state shows
- [ ] Can find headings in test post
- [ ] Can match images from media library
- [ ] Can preview matched images
- [ ] Can insert single image
- [ ] Can insert all images
- [ ] Can undo insertions
- [ ] Images appear in post content
- [ ] Post saves correctly

---

## 📸 Screenshots Location

When testing, look for the button here:

**Gutenberg:**
```
┌─────────────────────────────────────────┐
│ [Save] [Preview] [⚙️] [Smart Image Matcher]│ ← HERE
├─────────────────────────────────────────┤
│ Add title                               │
│ Type / to choose a block                │
└─────────────────────────────────────────┘
```

**Classic Editor:**
```
┌─────────────────────────────────────────┐
│ Edit Post                               │
├─────────────────────────────────────────┤
│ [Enter title here___________________]   │
│                                         │
│ [🖼️ Smart Image Matcher]               │ ← HERE
│                                         │
│ ┌─────────────────────────────────────┐│
│ │ Visual | Text                       ││
│ │                                     ││
└─────────────────────────────────────────┘
```

---

## ✉️ Reporting Issues

If you encounter problems:

1. **Document the issue:**
   - WordPress version
   - PHP version
   - Active theme
   - Other active plugins
   - Browser and version
   - Screenshots of error

2. **Check browser console:**
   - Press F12
   - Copy any red errors
   - Include in report

3. **Test with default theme:**
   - Switch to Twenty Twenty-Four theme
   - Test if issue persists

---

**Last Updated:** 12/10/2025  
**Version:** 1.0.2  
**Status:** ✅ Ready for Testing

