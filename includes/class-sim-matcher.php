<?php
/**
 * Filename: class-sim-matcher.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.0.4
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
        $filename = strtolower(pathinfo($image['filename'], PATHINFO_FILENAME));
        $filename = str_replace(array('-', '_'), ' ', $filename);
        $filename_words = preg_split('/\s+/', $filename);
        
        $title = strtolower($image['title']);
        $title_words = preg_split('/\s+/', preg_replace('/[^a-z0-9\s]/', '', $title));
        
        $alt = strtolower($image['alt']);
        $alt_words = preg_split('/\s+/', preg_replace('/[^a-z0-9\s]/', '', $alt));
        
        $caption = strtolower($image['caption']);
        
        $heading_text = strtolower(implode(' ', $heading_keywords));
        
        $scores = array();
        
        // Score 1: Filename exact match check
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
            
            // Penalty for extra words (dilution)
            $extra_words = count($filename_words) - count($heading_keywords);
            if ($extra_words > 3) {
                $filename_score *= 0.85; // 15% penalty for verbose filenames
            }
            
            $scores[] = array('field' => 'filename', 'score' => $filename_score, 'weight' => 1.0);
        }
        
        // Score 2: Title exact match check
        $title_matches = 0;
        foreach ($heading_keywords as $keyword) {
            if (in_array($keyword, $title_words)) {
                $title_matches++;
            }
        }
        if ($title_matches > 0) {
            $title_score = ($title_matches / count($heading_keywords)) * 90;
            
            // Bonus for exact phrase match in title
            if (strpos($title, $heading_text) !== false) {
                $title_score = 90;
            }
            
            // Penalty for extra words
            $extra_words = count($title_words) - count($heading_keywords);
            if ($extra_words > 3) {
                $title_score *= 0.85;
            }
            
            $scores[] = array('field' => 'title', 'score' => $title_score, 'weight' => 0.9);
        }
        
        // Score 3: Alt text exact match check
        $alt_matches = 0;
        foreach ($heading_keywords as $keyword) {
            if (in_array($keyword, $alt_words)) {
                $alt_matches++;
            }
        }
        if ($alt_matches > 0) {
            $alt_score = ($alt_matches / count($heading_keywords)) * 85;
            
            // Bonus for exact phrase match in alt
            if (strpos($alt, $heading_text) !== false) {
                $alt_score = 85;
            }
            
            $scores[] = array('field' => 'alt', 'score' => $alt_score, 'weight' => 0.85);
        }
        
        // Score 4: Caption substring match
        $caption_matches = 0;
        foreach ($heading_keywords as $keyword) {
            if (strpos($caption, $keyword) !== false) {
                $caption_matches++;
            }
        }
        if ($caption_matches > 0) {
            $caption_score = ($caption_matches / count($heading_keywords)) * 30;
            $scores[] = array('field' => 'caption', 'score' => $caption_score, 'weight' => 0.3);
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

