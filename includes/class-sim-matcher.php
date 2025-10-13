<?php
/**
 * Filename: class-sim-matcher.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.1.0
 * Last Modified: 12/10/2025
 * Description: Matching engine for keyword-based and AI-powered image matching
 * 
 * Scoring Priority:
 * 1. Filename - 100 points (PRIMARY - always exists)
 * 2. Title - 90 points (+10 bonus if intentionally set/different from filename)
 * 3. Alt Text - 85 points (SEO critical)
 * Note: Caption removed (rarely used, inconsistent)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIM_Matcher {
    
    public static function find_matches_for_post($post_id, $mode = 'keyword') {
        $post = get_post($post_id);
        
        if (!$post) {
            return new WP_Error('invalid_post', __('Invalid post ID', 'smart-image-matcher'));
        }
        
        $headings = self::extract_headings($post->post_content);
        
        if (empty($headings)) {
            return array();
        }
        
        $hierarchy_mode = get_option('sim_hierarchy_mode', 'smart');
        $filtered_headings = self::filter_headings_by_hierarchy($headings, $post->post_content, $hierarchy_mode);
        
        $media_library = SIM_Cache::get_cached_media_library();
        
        $matches = array();
        
        foreach ($filtered_headings as $heading) {
            if ($mode === 'ai') {
                $heading_matches = SIM_AI::find_ai_matches($heading, $media_library);
            } else {
                $heading_matches = self::find_keyword_matches($heading, $media_library);
            }
            
            if (!empty($heading_matches)) {
                $matches[] = array(
                    'heading' => $heading,
                    'matches' => $heading_matches,
                );
            } else {
                $matches[] = array(
                    'heading' => $heading,
                    'matches' => array(),
                );
            }
        }
        
        return $matches;
    }
    
    public static function extract_headings($content) {
        $headings = array();
        
        preg_match_all('/<(h[2-6])[^>]*>(.*?)<\/\1>/is', $content, $matches, PREG_OFFSET_CAPTURE);
        
        if (empty($matches[0])) {
            return $headings;
        }
        
        foreach ($matches[0] as $index => $match) {
            $tag = $matches[1][$index][0];
            $text = $matches[2][$index][0];
            $position = $matches[0][$index][1];
            
            $text = wp_strip_all_tags($text);
            $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
            $text = trim($text);
            
            if (empty($text)) {
                continue;
            }
            
            $headings[] = array(
                'tag' => $tag,
                'text' => $text,
                'position' => $position,
                'level' => intval(substr($tag, 1)),
            );
        }
        
        return $headings;
    }
    
    public static function filter_headings_by_hierarchy($headings, $content, $mode) {
        if ($mode === 'all') {
            return $headings;
        }
        
        if ($mode === 'primary') {
            return array_filter($headings, function($heading) {
                return $heading['tag'] === 'h2';
            });
        }
        
        if ($mode === 'smart') {
            return self::apply_smart_hierarchy($headings, $content);
        }
        
        return $headings;
    }
    
    public static function apply_smart_hierarchy($headings, $content) {
        $filtered = array();
        $overlap_threshold = get_option('sim_heading_overlap_threshold', 70);
        $last_h2_keywords = array();
        
        foreach ($headings as $index => $heading) {
            if ($heading['level'] === 2) {
                $filtered[] = $heading;
                $last_h2_keywords = self::extract_keywords($heading['text']);
                continue;
            }
            
            if ($heading['level'] > 2) {
                $current_keywords = self::extract_keywords($heading['text']);
                
                if (empty($last_h2_keywords)) {
                    $filtered[] = $heading;
                    continue;
                }
                
                $overlap = self::calculate_keyword_overlap($last_h2_keywords, $current_keywords);
                
                if ($overlap < $overlap_threshold) {
                    $filtered[] = $heading;
                }
            }
        }
        
        return $filtered;
    }
    
    public static function find_keyword_matches($heading, $media_library) {
        $heading_keywords = self::extract_keywords($heading['text']);
        
        $confidence_threshold = get_option('sim_confidence_threshold', 70);
        
        $scored_images = array();
        
        foreach ($media_library as $image) {
            $score = self::calculate_match_score($heading_keywords, $image);
            
            if ($score >= $confidence_threshold) {
                $scored_images[] = array(
                    'image_id' => $image['id'],
                    'confidence_score' => $score,
                    'match_method' => 'keyword',
                    'image_url' => $image['url'],
                    'filename' => $image['filename'],
                    'title' => $image['title'],
                );
            }
        }
        
        usort($scored_images, function($a, $b) {
            return $b['confidence_score'] - $a['confidence_score'];
        });
        
        return array_slice($scored_images, 0, 3);
    }
    
    public static function extract_keywords($text) {
        $text = strtolower($text);
        
        // Replace common separators with spaces BEFORE removing special chars
        // This prevents words from merging (e.g., "female/immature" -> "female immature" not "femaleimmature")
        $text = str_replace(array('/', ',', '|', ';', ':', '(', ')', '[', ']'), ' ', $text);
        
        // Now remove remaining special characters (keep only letters, numbers, spaces, hyphens)
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        
        // Split into words
        $words = preg_split('/\s+/', $text);
        
        $stop_words = array(
            'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
            'of', 'with', 'by', 'from', 'as', 'is', 'was', 'are', 'been', 'be',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
            'should', 'may', 'might', 'can', 'this', 'that', 'these', 'those'
        );
        
        $keywords = array_filter($words, function($word) use ($stop_words) {
            return strlen($word) > 2 && !in_array($word, $stop_words);
        });
        
        return array_values($keywords);
    }
    
    public static function calculate_match_score($heading_keywords, $image) {
        $filename = strtolower(pathinfo($image['filename'], PATHINFO_FILENAME));
        $filename = str_replace(array('-', '_'), ' ', $filename);
        $filename_words = preg_split('/\s+/', $filename);
        
        $title = strtolower($image['title']);
        $title_words = preg_split('/\s+/', preg_replace('/[^a-z0-9\s]/', '', $title));
        
        $alt = strtolower($image['alt']);
        $alt_words = preg_split('/\s+/', preg_replace('/[^a-z0-9\s]/', '', $alt));
        
        $heading_text = strtolower(implode(' ', $heading_keywords));
        
        // Check if title is intentionally set (different from filename)
        $filename_normalized = strtolower(preg_replace('/[^a-z0-9\s]/', '', $filename));
        $title_normalized = strtolower(preg_replace('/[^a-z0-9\s]/', '', $title));
        $title_is_intentional = !empty($title) && ($title_normalized !== $filename_normalized);
        
        $scores = array();
        
        // Score 1: Filename matching (PRIMARY - 100 points)
        $filename_matches = 0;
        foreach ($heading_keywords as $keyword) {
            if (in_array($keyword, $filename_words)) {
                $filename_matches++;
            }
        }
        if ($filename_matches > 0) {
            $filename_score = ($filename_matches / count($heading_keywords)) * 100;
            
            // Bonus for exact phrase match in filename
            if (strpos($filename, $heading_text) !== false) {
                $filename_score = 100;
            }
            
            // Smart penalty/bonus for word count matching
            $extra_words = count($filename_words) - count($heading_keywords);
            
            if ($extra_words === 0) {
                // PERFECT: Exact word count match - BOOST
                $filename_score = min($filename_score * 1.1, 100); // 10% bonus
            } elseif ($extra_words > 0) {
                // Penalty for extra words - graduated scale
                // 1 extra word = 10% penalty
                // 2 extra words = 18% penalty
                // 3+ extra words = 25% penalty
                if ($extra_words === 1) {
                    $filename_score *= 0.90; // 10% penalty
                } elseif ($extra_words === 2) {
                    $filename_score *= 0.82; // 18% penalty
                } else {
                    $filename_score *= 0.75; // 25% penalty for 3+ extra words
                }
            }
            
            $scores[] = array('field' => 'filename', 'score' => $filename_score, 'weight' => 1.0);
        }
        
        // Score 2: Title matching (90 points + intentional bonus)
        $title_matches = 0;
        foreach ($heading_keywords as $keyword) {
            if (in_array($keyword, $title_words)) {
                $title_matches++;
            }
        }
        if ($title_matches > 0 && !empty($title)) {
            $title_score = ($title_matches / count($heading_keywords)) * 90;
            
            // Bonus for exact phrase match in title
            if (strpos($title, $heading_text) !== false) {
                $title_score = 90;
            }
            
            // BONUS: If title is intentionally set (different from filename), add +10 points
            if ($title_is_intentional) {
                $title_score = min($title_score + 10, 100);
            }
            
            // Smart penalty/bonus for word count matching
            $extra_words = count($title_words) - count($heading_keywords);
            
            if ($extra_words === 0) {
                $title_score = min($title_score * 1.1, 100); // 10% bonus for exact match
            } elseif ($extra_words > 0) {
                if ($extra_words === 1) {
                    $title_score *= 0.90; // 10% penalty
                } elseif ($extra_words === 2) {
                    $title_score *= 0.82; // 18% penalty
                } else {
                    $title_score *= 0.75; // 25% penalty
                }
            }
            
            $scores[] = array('field' => 'title', 'score' => $title_score, 'weight' => 0.9);
        }
        
        // Score 3: Alt text matching (85 points)
        $alt_matches = 0;
        foreach ($heading_keywords as $keyword) {
            if (in_array($keyword, $alt_words)) {
                $alt_matches++;
            }
        }
        if ($alt_matches > 0 && !empty($alt)) {
            $alt_score = ($alt_matches / count($heading_keywords)) * 85;
            
            // Bonus for exact phrase match in alt
            if (strpos($alt, $heading_text) !== false) {
                $alt_score = 85;
            }
            
            // Smart penalty/bonus for word count matching
            $extra_words = count($alt_words) - count($heading_keywords);
            
            if ($extra_words === 0) {
                $alt_score = min($alt_score * 1.1, 85); // 10% bonus for exact match
            } elseif ($extra_words > 0) {
                if ($extra_words === 1) {
                    $alt_score *= 0.90; // 10% penalty
                } elseif ($extra_words === 2) {
                    $alt_score *= 0.82; // 18% penalty
                } else {
                    $alt_score *= 0.75; // 25% penalty
                }
            }
            
            $scores[] = array('field' => 'alt', 'score' => $alt_score, 'weight' => 0.85);
        }
        
        // Calculate final score: Use highest weighted score
        if (empty($scores)) {
            return 0;
        }
        
        $final_score = 0;
        foreach ($scores as $s) {
            $weighted = $s['score'] * $s['weight'];
            if ($weighted > $final_score) {
                $final_score = $weighted;
            }
        }
        
        // Bonus: If ALL keywords match in ANY field, boost to near-perfect
        if ($filename_matches == count($heading_keywords)) {
            $final_score = max($final_score, 95);
            
            // Perfect match: exact phrase in filename
            if (strpos($filename, $heading_text) !== false) {
                $final_score = 100;
            }
        }
        
        if ($title_matches == count($heading_keywords)) {
            $final_score = max($final_score, 92);
            
            // Perfect match: exact phrase in title
            if (strpos($title, $heading_text) !== false) {
                $final_score = max($final_score, 98);
            }
            
            // Additional boost if title is intentionally set
            if ($title_is_intentional && $title_matches == count($heading_keywords)) {
                $final_score = min($final_score + 5, 100);
            }
        }
        
        return min(round($final_score), 100);
    }
    
    public static function calculate_keyword_overlap($keywords1, $keywords2) {
        if (empty($keywords1) || empty($keywords2)) {
            return 0;
        }
        
        $intersection = array_intersect($keywords1, $keywords2);
        $union = array_unique(array_merge($keywords1, $keywords2));
        
        return (count($intersection) / count($union)) * 100;
    }
}

