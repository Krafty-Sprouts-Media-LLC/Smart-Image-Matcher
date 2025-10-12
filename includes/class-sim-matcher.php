<?php
/**
 * Filename: class-sim-matcher.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.0.1
 * Last Modified: 12/10/2025
 * Description: Matching engine for keyword-based and AI-powered image matching
 * 
 * Scoring Priority:
 * 1. Filename - 100 points (e.g., "black-swallowtail-caterpillar.jpg")
 * 2. Title - 90 points (WordPress image title field)
 * 3. Alt Text - 85 points (SEO critical, almost always filled)
 * 4. Caption - 30 points (often empty)
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
        
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        
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
        $score = 0;
        
        $filename = strtolower(pathinfo($image['filename'], PATHINFO_FILENAME));
        $filename = str_replace(array('-', '_'), ' ', $filename);
        $filename_words = preg_split('/\s+/', $filename);
        
        $title = strtolower($image['title']);
        $alt = strtolower($image['alt']);
        $caption = strtolower($image['caption']);
        
        $exact_match = true;
        foreach ($heading_keywords as $keyword) {
            if (!in_array($keyword, $filename_words)) {
                $exact_match = false;
                break;
            }
        }
        
        if ($exact_match && !empty($heading_keywords)) {
            $score += 100;
            return min($score, 100);
        }
        
        foreach ($heading_keywords as $keyword) {
            if (in_array($keyword, $filename_words)) {
                $score += 100 / count($heading_keywords);
            }
            
            if (strpos($title, $keyword) !== false) {
                $score += 90 / count($heading_keywords);
            }
            
            if (strpos($alt, $keyword) !== false) {
                $score += 85 / count($heading_keywords);
            }
            
            if (strpos($caption, $keyword) !== false) {
                $score += 30 / count($heading_keywords);
            }
        }
        
        return min(round($score), 100);
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

