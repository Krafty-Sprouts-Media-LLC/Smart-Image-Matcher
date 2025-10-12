<?php
/**
 * Filename: class-sim-ai.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.0.1
 * Last Modified: 12/10/2025
 * Description: Claude API integration for AI-powered image matching
 * 
 * AI matching sends candidate images with metadata in priority order:
 * Filename, Title, Alt Text, Caption
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIM_AI {
    
    public static function find_ai_matches($heading, $media_library, $candidate_count = 10) {
        $api_key = get_option('sim_claude_api_key', '');
        
        if (empty($api_key)) {
            return SIM_Matcher::find_keyword_matches($heading, $media_library);
        }
        
        $rate_limit_check = self::check_rate_limit();
        if (is_wp_error($rate_limit_check)) {
            return SIM_Matcher::find_keyword_matches($heading, $media_library);
        }
        
        $keyword_matches = SIM_Matcher::find_keyword_matches($heading, $media_library);
        
        if (empty($keyword_matches)) {
            $candidates = array_slice($media_library, 0, $candidate_count);
        } else {
            $candidate_ids = array_slice(array_column($keyword_matches, 'image_id'), 0, $candidate_count);
            $candidates = array_filter($media_library, function($image) use ($candidate_ids) {
                return in_array($image['id'], $candidate_ids);
            });
        }
        
        $response = self::call_claude_api($heading, $candidates);
        
        if (is_wp_error($response)) {
            return $keyword_matches;
        }
        
        self::increment_api_usage();
        
        return $response;
    }
    
    public static function call_claude_api($heading, $candidates) {
        $api_key = get_option('sim_claude_api_key', '');
        $model = get_option('sim_claude_model', 'claude-sonnet-4-20250514');
        
        $candidate_list = array();
        foreach ($candidates as $image) {
            $candidate_list[] = sprintf(
                "ID: %d, Filename: %s, Title: %s, Alt: %s, Caption: %s",
                $image['id'],
                $image['filename'],
                $image['title'],
                $image['alt'],
                $image['caption']
            );
        }
        
        $prompt = sprintf(
            "Given heading: \"%s\"\n\nRank these images by relevance (0-100):\n%s\n\nRespond ONLY with valid JSON in this exact format:\n{\"matches\": [{\"image_id\": 123, \"relevance_score\": 95, \"reasoning\": \"Exact match...\", \"confidence\": \"high\"}]}",
            $heading['text'],
            implode("\n", $candidate_list)
        );
        
        $body = array(
            'model' => $model,
            'max_tokens' => 1024,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            )
        );
        
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01'
            ),
            'body' => wp_json_encode($body),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new WP_Error('api_error', __('Claude API returned error', 'smart-image-matcher'));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data['content'][0]['text'])) {
            return new WP_Error('invalid_response', __('Invalid API response', 'smart-image-matcher'));
        }
        
        $content = $data['content'][0]['text'];
        $matches_data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || empty($matches_data['matches'])) {
            return new WP_Error('parse_error', __('Failed to parse API response', 'smart-image-matcher'));
        }
        
        $matches = array();
        foreach ($matches_data['matches'] as $match) {
            if ($match['relevance_score'] >= get_option('sim_confidence_threshold', 70)) {
                $image = array_values(array_filter($candidates, function($img) use ($match) {
                    return $img['id'] == $match['image_id'];
                }));
                
                if (!empty($image)) {
                    $matches[] = array(
                        'image_id' => $match['image_id'],
                        'confidence_score' => $match['relevance_score'],
                        'match_method' => 'ai',
                        'ai_reasoning' => $match['reasoning'],
                        'image_url' => $image[0]['url'],
                        'filename' => $image[0]['filename'],
                    );
                }
            }
        }
        
        return $matches;
    }
    
    public static function check_rate_limit() {
        $hour_calls = get_transient('sim_api_calls_hour');
        $day_calls = get_transient('sim_api_calls_day');
        
        if ($hour_calls === false) {
            $hour_calls = 0;
        }
        if ($day_calls === false) {
            $day_calls = 0;
        }
        
        if ($hour_calls >= SIM_MAX_API_CALLS_PER_HOUR) {
            return new WP_Error('rate_limit', __('Hourly API limit reached', 'smart-image-matcher'));
        }
        
        if ($day_calls >= SIM_MAX_API_CALLS_PER_DAY) {
            return new WP_Error('rate_limit', __('Daily API limit reached', 'smart-image-matcher'));
        }
        
        return true;
    }
    
    public static function increment_api_usage() {
        $hour_calls = get_transient('sim_api_calls_hour');
        $day_calls = get_transient('sim_api_calls_day');
        
        if ($hour_calls === false) {
            $hour_calls = 0;
        }
        if ($day_calls === false) {
            $day_calls = 0;
        }
        
        set_transient('sim_api_calls_hour', $hour_calls + 1, HOUR_IN_SECONDS);
        set_transient('sim_api_calls_day', $day_calls + 1, DAY_IN_SECONDS);
    }
}

