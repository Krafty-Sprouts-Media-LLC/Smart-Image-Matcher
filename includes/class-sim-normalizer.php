<?php
/**
 * Filename: class-sim-normalizer.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 21/10/2025
 * Version: 1.0.0
 * Last Modified: 21/10/2025
 * Description: Advanced text normalization with stemming, spelling variants, and linguistic enhancements
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIM_Normalizer {
    
    /**
     * US/British spelling variations dictionary
     */
    private static $spelling_variants = array(
        'color' => 'colour',
        'gray' => 'grey',
        'center' => 'centre',
        'meter' => 'metre',
        'fiber' => 'fibre',
        'theater' => 'theatre',
        'organize' => 'organise',
        'recognize' => 'recognise',
        'realize' => 'realise',
        'analyze' => 'analyse',
        'paralyze' => 'paralyse',
        'catalog' => 'catalogue',
        'dialog' => 'dialogue',
        'traveler' => 'traveller',
        'canceled' => 'cancelled',
        'labeled' => 'labelled',
        'modeling' => 'modelling',
        'flavor' => 'flavour',
        'honor' => 'honour',
        'labor' => 'labour',
        'neighbor' => 'neighbour',
        'vigor' => 'vigour',
        'defense' => 'defence',
        'offense' => 'offence',
        'license' => 'licence',
        'practice' => 'practise',
        'aging' => 'ageing',
        'jewelry' => 'jewellery',
        'tire' => 'tyre',
        'plow' => 'plough',
    );
    
    /**
     * Common irregular plurals
     */
    private static $irregular_plurals = array(
        'child' => 'children',
        'person' => 'people',
        'man' => 'men',
        'woman' => 'women',
        'tooth' => 'teeth',
        'foot' => 'feet',
        'mouse' => 'mice',
        'goose' => 'geese',
        'ox' => 'oxen',
        'leaf' => 'leaves',
        'life' => 'lives',
        'knife' => 'knives',
        'wife' => 'wives',
        'half' => 'halves',
        'calf' => 'calves',
        'shelf' => 'shelves',
        'wolf' => 'wolves',
        'thief' => 'thieves',
        'loaf' => 'loaves',
        'deer' => 'deer',
        'sheep' => 'sheep',
        'fish' => 'fish',
        'species' => 'species',
        'series' => 'series',
    );
    
    /**
     * Normalize text with enhanced linguistic processing
     * 
     * @param string $text The text to normalize
     * @param bool $enable_stemming Enable singular/plural handling
     * @param bool $enable_spelling_variants Enable US/British spelling variants
     * @return array Array of normalized keywords
     */
    public static function normalize_text($text, $enable_stemming = true, $enable_spelling_variants = true) {
        $text = strtolower($text);
        
        // Handle possessives BEFORE other processing
        // "bird's nest" -> "bird nest"
        // "birds' nests" -> "birds nests"
        $text = preg_replace("/([a-z])'s?\b/", '$1', $text);
        
        // Replace common separators with spaces
        $text = str_replace(array('/', ',', '|', ';', ':', '(', ')', '[', ']'), ' ', $text);
        
        // Remove remaining special characters (keep only letters, numbers, spaces, hyphens)
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        
        // Split into words
        $words = preg_split('/\s+/', $text);
        
        // Remove stop words
        $stop_words = array(
            'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
            'of', 'with', 'by', 'from', 'as', 'is', 'was', 'are', 'been', 'be',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
            'should', 'may', 'might', 'can', 'this', 'that', 'these', 'those'
        );
        
        $keywords = array_filter($words, function($word) use ($stop_words) {
            return strlen($word) > 2 && !in_array($word, $stop_words);
        });
        
        // Apply stemming if enabled
        if ($enable_stemming) {
            $keywords = array_map(array('self', 'stem_word'), $keywords);
        }
        
        // Generate spelling variants if enabled
        if ($enable_spelling_variants) {
            $expanded = array();
            foreach ($keywords as $keyword) {
                $expanded[] = $keyword;
                $variants = self::get_spelling_variants($keyword);
                $expanded = array_merge($expanded, $variants);
            }
            $keywords = array_unique($expanded);
        }
        
        return array_values($keywords);
    }
    
    /**
     * Simple but effective stemming algorithm (Porter-like)
     * Handles common singular/plural forms
     * 
     * @param string $word The word to stem
     * @return string The stemmed word
     */
    public static function stem_word($word) {
        // Check irregular plurals first
        if (in_array($word, self::$irregular_plurals)) {
            return $word; // Keep as is, will match both forms
        }
        
        // Check if word is an irregular plural (reverse lookup)
        $irregular_singular = array_search($word, self::$irregular_plurals);
        if ($irregular_singular !== false) {
            return $irregular_singular; // Return singular form
        }
        
        $original = $word;
        
        // Rule 1: -ies -> -y (babies -> baby, berries -> berry)
        if (preg_match('/(.+)ies$/', $word, $matches) && strlen($matches[1]) > 1) {
            return $matches[1] . 'y';
        }
        
        // Rule 2: -es after s, x, z, ch, sh (boxes -> box, churches -> church)
        if (preg_match('/(.+)(ss|x|z|ch|sh)es$/', $word, $matches)) {
            return $matches[1] . $matches[2];
        }
        
        // Rule 3: -ves -> -f/-fe (wolves -> wolf, knives -> knife)
        if (preg_match('/(.+)ves$/', $word, $matches) && strlen($matches[1]) > 1) {
            // Try -f first
            $candidate = $matches[1] . 'f';
            return $candidate;
        }
        
        // Rule 4: -s (simple plural) but not -ss, -us, -is
        if (preg_match('/(.{3,})s$/', $word, $matches) && 
            !preg_match('/(ss|us|is)$/', $word)) {
            return $matches[1];
        }
        
        return $word;
    }
    
    /**
     * Get spelling variants (US/British) for a word
     * 
     * @param string $word The word to check
     * @return array Array of spelling variants
     */
    public static function get_spelling_variants($word) {
        $variants = array();
        
        // Check if word is US spelling
        if (isset(self::$spelling_variants[$word])) {
            $variants[] = self::$spelling_variants[$word];
        }
        
        // Check if word is British spelling (reverse lookup)
        $us_spelling = array_search($word, self::$spelling_variants);
        if ($us_spelling !== false) {
            $variants[] = $us_spelling;
        }
        
        return $variants;
    }
    
    /**
     * Check if two words match considering linguistic variations
     * 
     * @param string $word1 First word
     * @param string $word2 Second word
     * @param bool $enable_stemming Enable singular/plural matching
     * @param bool $enable_spelling_variants Enable US/British spelling matching
     * @return bool True if words match
     */
    public static function words_match($word1, $word2, $enable_stemming = true, $enable_spelling_variants = true) {
        $word1 = strtolower(trim($word1));
        $word2 = strtolower(trim($word2));
        
        // Exact match
        if ($word1 === $word2) {
            return true;
        }
        
        // Strip possessives and compare
        $word1_no_poss = preg_replace("/([a-z])'s?\b/", '$1', $word1);
        $word2_no_poss = preg_replace("/([a-z])'s?\b/", '$1', $word2);
        
        if ($word1_no_poss === $word2_no_poss) {
            return true;
        }
        
        // Check stemmed forms
        if ($enable_stemming) {
            if (self::stem_word($word1) === self::stem_word($word2)) {
                return true;
            }
        }
        
        // Check spelling variants
        if ($enable_spelling_variants) {
            $variants1 = array_merge(array($word1), self::get_spelling_variants($word1));
            $variants2 = array_merge(array($word2), self::get_spelling_variants($word2));
            
            foreach ($variants1 as $v1) {
                if (in_array($v1, $variants2)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Count matching keywords between two sets with linguistic awareness
     * 
     * @param array $keywords1 First set of keywords
     * @param array $keywords2 Second set of keywords
     * @param bool $enable_stemming Enable singular/plural matching
     * @param bool $enable_spelling_variants Enable US/British spelling matching
     * @return int Number of matches
     */
    public static function count_matches($keywords1, $keywords2, $enable_stemming = true, $enable_spelling_variants = true) {
        $matches = 0;
        
        foreach ($keywords1 as $kw1) {
            foreach ($keywords2 as $kw2) {
                if (self::words_match($kw1, $kw2, $enable_stemming, $enable_spelling_variants)) {
                    $matches++;
                    break; // Count each keyword1 only once
                }
            }
        }
        
        return $matches;
    }
}

