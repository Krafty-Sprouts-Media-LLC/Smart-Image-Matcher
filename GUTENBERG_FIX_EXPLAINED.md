# Gutenberg Block Validation Fix - v1.1.1

**Date:** 12/10/2025  
**Critical Issue:** Block validation failed  
**Root Cause:** Width/height attributes in img tag

---

## 🐛 The Problem

### What Gutenberg Was Complaining About

```javascript
Block validation: Expected attributes Array(3), instead saw Array(5)

Expected (save function):
<img src="..." alt="..." class="wp-image-246527"/>  
// 3 attributes: src, alt, class

Got (from database):
<img src="..." alt="..." class="wp-image-246527" width="1024" height="683"/>
// 5 attributes: src, alt, class, width, height
```

---

## ✅ The Solution

### Remove Width/Height from Img Tag

**Gutenberg's sizeSlug handles dimensions automatically!**

```html
<!-- wp:image {"id":246527,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large">
    <img src="..." alt="..." class="wp-image-246527"/>
    <!-- ↑ ONLY 3 attributes! -->
</figure>
<!-- /wp:image -->
```

**Key Insight:** The `"sizeSlug":"large"` in the block comment tells Gutenberg to use the "large" image size, so width/height in the img tag are redundant and cause validation errors!

---

## 📋 Version 1.1.1 Implementation

### create_image_block() Function

```php
private static function create_image_block($image_id) {
    $image_url = wp_get_attachment_url($image_id);
    $alt_text = get_post_meta($image_id, '_wp_attachment_image_alt', true);
    $caption = wp_get_attachment_caption($image_id);
    
    // Block comment - ONLY these 3 attributes
    $block = sprintf(
        '<!-- wp:image {"id":%d,"sizeSlug":"large","linkDestination":"none"} -->',
        $image_id
    );
    
    $block .= "\n<figure class=\"wp-block-image size-large\">";
    
    // Img tag - ONLY 3 attributes (src, alt, class)
    $block .= sprintf(
        '<img src="%s" alt="%s" class="wp-image-%d"/>',
        esc_url($image_url),
        esc_attr($alt_text),
        $image_id
    );
    
    // Caption (optional)
    if (!empty($caption)) {
        $block .= '<figcaption class="wp-element-caption">' . 
                  wp_kses_post($caption) . 
                  '</figcaption>';
    }
    
    $block .= '</figure>' . "\n" . '<!-- /wp:image -->';
    
    return $block;
}
```

---

## 🎯 What Changed

### ❌ REMOVED (Causing Validation Error)

```php
// DON'T DO THIS:
$metadata = wp_get_attachment_metadata($image_id);
$width = $metadata['width'];
$height = $metadata['height'];

$block .= sprintf(
    '<img ... width="%d" height="%d"/>',  // ← WRONG!
    $width,
    $height
);
```

### ✅ CORRECT (Passes Validation)

```php
// DO THIS:
$block .= sprintf(
    '<img src="%s" alt="%s" class="wp-image-%d"/>',  // ← CORRECT!
    esc_url($image_url),
    esc_attr($alt_text),
    $image_id
);
```

---

## 🔍 Why This Works

1. **Block Comment Says:** `"sizeSlug":"large"`
   - This tells Gutenberg: "Use the 'large' image size"
   - Gutenberg knows the dimensions of 'large' images
   - No need to specify width/height

2. **Img Tag Has:** `src, alt, class` (3 attributes)
   - Matches what Gutenberg's save function expects
   - Gutenberg adds width/height when rendering on frontend
   - Validation passes!

3. **WordPress Adds Dimensions Later:**
   - When displaying on frontend, WordPress adds width/height
   - When editing, Gutenberg knows dimensions from sizeSlug
   - Everyone's happy!

---

## 📊 Gutenberg Block Lifecycle

### 1. Editor (Save Function)
```html
<!-- Saved to database -->
<img src="..." alt="..." class="wp-image-X"/>
```

### 2. Frontend (Render)
```html
<!-- WordPress adds width/height for display -->
<img src="..." alt="..." class="wp-image-X" width="1024" height="683"/>
```

### 3. Editor Load (Validation)
```javascript
// Gutenberg checks: Does saved content match save function output?
Expected: <img with 3 attributes>
Got: <img with 3 attributes>
✅ Validation passes!
```

---

## ✅ Benefits of This Approach

1. **Clean Code** - Simple, minimal
2. **WordPress Way** - Let Gutenberg handle sizing
3. **Responsive** - sizeSlug works with responsive images
4. **Validation** - Passes Gutenberg checks
5. **Future-Proof** - Matches Gutenberg schema

---

## 🚀 Test Results Expected

After uploading v1.1.1:

**Console Should Show:**
```
SIM: Created Gutenberg block without width/height
✅ No "Block validation failed" errors
✅ Images appear after reload
```

**What You'll See:**
- Images inserted ✅
- No validation warnings ✅
- Clean block format ✅
- Captions preserved ✅

---

**Version 1.1.1 = The Correct Gutenberg Format!** 🎉

