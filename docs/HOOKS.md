# Smart Image Matcher — Hooks Reference

All actions and filters this plugin defines for third-party developers.
Updated as each phase is implemented.

---

## Actions

### `sim_clear_post_cache`

Fires after Smart Image Matcher has cleared cache for a post. Use this to clear any custom caching layer not handled by the built-in compat layer.

**Since:** 3.0.0

**Parameters:**

| Param | Type | Description |
|---|---|---|
| `$post_id` | `int` | The post ID whose cache was cleared. |

**Example:**
```php
add_action( 'sim_clear_post_cache', function( int $post_id ) {
    my_custom_cache_flush( $post_id );
} );
```

---

## Filters

### `sim_premium_feature_active`

Filter whether a premium feature is enabled.

**Since:** 3.0.0

**Parameters:**

| Param | Type | Description |
|---|---|---|
| `$active` | `bool` | Current state. |
| `$slug` | `string` | Feature slug (e.g. `'bulk_processor'`). |

**Example — disable bulk processor for all users:**
```php
add_filter( 'sim_premium_feature_active', function( bool $active, string $slug ): bool {
    if ( 'bulk_processor' === $slug ) {
        return false;
    }
    return $active;
}, 10, 2 );
```

### `sim_fiaa_batch_size`

Controls the number of posts processed per batch during Featured Image assignment runs.

**Since:** 3.0.0

**Parameters:**

| Param | Type | Description |
|---|---|---|
| `$batch_size` | `int` | Batch size (25–1000). |

---

*More hooks will be documented as each phase is implemented. Run `grep -r "do_action\|apply_filters" src/` to see all current hook registrations.*
