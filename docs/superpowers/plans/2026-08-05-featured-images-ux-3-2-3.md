# Featured Images UX 3.2.3 + Description Toggle — Implementation Plan

> **For agentic workers:** Implement tasks in order. Spec: `docs/superpowers/specs/2026-08-05-featured-images-ux-merge-modal-design.md` + save-prompt-as-description setting.

**Goal:** One Featured Images admin home (Match + AI), posts-list dismissable modal, scan skip reasons, optional empty media Description.

**Version:** 3.2.3

## File map

| File | Change |
|---|---|
| `src/REST/ImageGenController.php` | Scan returns `skipped[]` with reason codes; explicit `post_ids` classified fully |
| `src/Settings/Settings.php` + `Sanitizer.php` | `ai_image_save_prompt_as_description` (default false); remove Generate submenu; redirect old slug; enqueue AI assets on Featured page |
| `src/Premium/AiImageGenerator.php` | Conditionally write `post_content` |
| `src/Compat/WpAiFeaturedImageCompat.php` | Same setting for WP AI imports |
| `admin/views/featured-images.php` | Embed AI Generate card (from generate-images) |
| `admin/views/generate-images.php` | Delete or redirect-only stub unused |
| `admin/js/src/generate-images.js` | Shared logic; work on Featured page selectors |
| `admin/js/src/featured-ai-bulk-modal.js` | NEW — posts list modal |
| `admin/css/sim-featured-ai-modal.css` | NEW — modal styles |
| `src/Admin/GenerateImagesBulkAction.php` | Stay on list + open modal (query args) |
| `src/Plugin.php` | Enqueue list modal assets; Featured page assets |
| Version files + CHANGELOG + readme | 3.2.3 |

## Task checklist

1. Setting + AiImageGenerator + WpAiFeaturedImageCompat  
2. Scan API skipped reasons  
3. Merge UI into Featured Images; remove submenu; redirect  
4. Posts-list modal + bulk action  
5. Version / changelog / syntax check  
