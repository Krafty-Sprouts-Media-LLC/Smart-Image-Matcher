/**
 * Filename: sim-editor.js
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.1.2
 * Last Modified: 26/10/2025
 * 
 * Simplified UX: No undo, just insert and reload with clear notices
 * Warning notice reminds users to review matches before inserting
 * Carousel feature: Browse multiple matches with smart preloading
 * Description: JavaScript for editor modal and image matching interface
 */

(function($) {
    'use strict';

    let currentMatches = [];
    let currentIndices = {}; // Track current image index for each heading
    let undoTimerId = null;

    $(document).ready(function() {
        $('#sim-open-modal, #sim-gutenberg-button').on('click', openModal);
        $('.sim-modal-close, .sim-cancel-button').on('click', closeModal);
        $('.sim-modal-overlay').on('click', closeModal);
        $('.sim-insert-all-button').on('click', insertAllSelected);
        
        $(document).on('click', '.sim-insert-single-button', insertSingleImage);
        $(document).on('click', '#sim-gutenberg-button', openModal);
        $(document).on('click', '.sim-carousel-prev', navigatePrev);
        $(document).on('click', '.sim-carousel-next', navigateNext);
        $(document).on('change', '.sim-select-checkbox', handleCheckboxChange);
        
        // Keyboard navigation
        $(document).on('keydown', function(e) {
            if ($('#sim-modal').is(':visible')) {
                if (e.key === 'ArrowLeft') {
                    e.preventDefault();
                    $('.sim-match-item:visible .sim-carousel-prev').first().click();
                } else if (e.key === 'ArrowRight') {
                    e.preventDefault();
                    $('.sim-match-item:visible .sim-carousel-next').first().click();
                }
            }
        });
        
        window.simFindMatches = findMatches;
    });

    function openModal() {
        $('#sim-modal').show();
        showLoadingState();
        findMatches();
    }

    function closeModal() {
        $('#sim-modal').hide();
        if (undoTimerId) {
            clearInterval(undoTimerId);
        }
    }

    function showLoadingState() {
        $('.sim-loading-state').show();
        $('.sim-results-state').hide();
        $('.sim-error-state').hide();
        $('.sim-insert-all-button').hide();
        
        updateProgress(0, simEditor.strings.analyzingContent);
    }

    function updateProgress(percent, message) {
        $('.sim-progress-fill').css('width', percent + '%');
        if (message) {
            $('.sim-loading-info').text(message);
        }
    }

    function findMatches() {
        updateProgress(30);
        
        console.log('SIM: Starting AJAX request');
        console.log('SIM: AJAX URL:', simEditor.ajaxUrl);
        console.log('SIM: Nonce:', simEditor.nonce);
        console.log('SIM: Post ID:', simEditor.postId);

        $.ajax({
            url: simEditor.ajaxUrl,
            type: 'POST',
            data: {
                action: 'sim_find_matches',
                nonce: simEditor.nonce,
                post_id: simEditor.postId,
                mode: 'keyword'
            },
            success: function(response) {
                console.log('SIM: AJAX success response:', response);
                updateProgress(100);
                
                if (response.success) {
                    currentMatches = response.data.matches;
                    displayResults();
                } else {
                    console.error('SIM: AJAX success but response.error:', response.data);
                    showError(response.data.message);
                }
            },
            error: function(xhr, status, error) {
                console.error('SIM: AJAX error details:', {
                    xhr: xhr,
                    status: status,
                    error: error,
                    responseText: xhr.responseText,
                    statusText: xhr.statusText
                });
                showError('AJAX error: ' + error + ' (Status: ' + xhr.status + ')');
            }
        });
    }

    function displayResults() {
        $('.sim-loading-state').hide();
        $('.sim-results-state').show();
        
        const totalHeadings = currentMatches.length;
        const matchedHeadings = currentMatches.filter(m => m.matches.length > 0).length;
        
        $('.sim-results-summary').html(
            '<strong>' + matchedHeadings + ' matches found for ' + totalHeadings + ' headings</strong>' +
            '<div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; font-size: 14px;">' +
            '<strong>⚠️ Please Review:</strong> Check each match for accuracy before inserting. ' +
            'Use arrows to browse alternative matches. Uncheck any incorrect matches.' +
            '</div>'
        );
        
        const container = $('.sim-matches-container');
        container.empty();
        currentIndices = {}; // Reset indices
        
        currentMatches.forEach(function(matchGroup) {
            const heading = matchGroup.heading;
            const matches = matchGroup.matches;
            
            if (matches.length > 0) {
                // Initialize current index for this heading
                currentIndices[heading.position] = 0;
                const matchHtml = createMatchHtml(heading, matches);
                container.append(matchHtml);
                
                // Preload next image (image #2) for instant navigation
                if (matches.length > 1) {
                    preloadImage(matches[1].image_url);
                }
            } else {
                const noMatchHtml = createNoMatchHtml(heading);
                container.append(noMatchHtml);
            }
        });
        
        $('.sim-insert-all-button').show();
    }

    function createMatchHtml(heading, matches) {
        const currentIndex = 0; // Always start with first match
        const match = matches[currentIndex];
        const totalMatches = matches.length;
        
        const confidenceClass = match.confidence_score >= 90 ? 'sim-confidence-high' :
                                match.confidence_score >= 70 ? 'sim-confidence-medium' :
                                'sim-confidence-low';
        
        let html = '<div class="sim-match-item" data-heading-position="' + heading.position + '" data-image-id="' + match.image_id + '" data-all-matches=\'' + JSON.stringify(matches) + '\'>';
        html += '<div class="sim-match-heading">';
        html += SimSvgIcons.check();
        html += '<span>' + heading.tag.toUpperCase() + ': ' + escapeHtml(heading.text) + '</span>';
        html += '</div>';
        
        // Carousel navigation (only show if multiple matches)
        if (totalMatches > 1) {
            html += '<div class="sim-carousel-controls">';
            html += '<button type="button" class="button sim-carousel-prev" ' + (currentIndex === 0 ? 'disabled' : '') + '>';
            html += SimSvgIcons.arrowLeft() + ' Prev';
            html += '</button>';
            html += '<span class="sim-carousel-counter">';
            if (currentIndex === 0) {
                html += '<span class="sim-best-match-badge">⭐ Best Match</span> ';
            }
            html += '<strong>Image <span class="sim-current-index">' + (currentIndex + 1) + '</span> of ' + totalMatches + '</strong>';
            html += '</span>';
            html += '<button type="button" class="button sim-carousel-next" ' + (currentIndex === totalMatches - 1 ? 'disabled' : '') + '>';
            html += 'Next ' + SimSvgIcons.arrowRight();
            html += '</button>';
            html += '</div>';
        }
        
        html += '<div class="sim-image-preview-container">';
        html += '<img src="' + match.image_url + '" alt="" class="sim-image-preview" />';
        html += '<div class="sim-image-info">';
        html += '<div class="sim-confidence-score ' + confidenceClass + '">';
        html += simEditor.strings.confidence + ': <span class="sim-confidence-value">' + match.confidence_score + '%</span>';
        html += '</div>';
        
        if (match.title) {
            html += '<div class="sim-filename sim-image-title"><strong>Image Title:</strong> <span class="sim-title-value">' + escapeHtml(match.title) + '</span></div>';
        }
        html += '<div class="sim-filename sim-image-filename"><strong>Filename:</strong> <span class="sim-filename-value">' + escapeHtml(match.filename) + '</span></div>';
        
        if (match.ai_reasoning) {
            html += '<div class="sim-ai-reasoning sim-reasoning-value">' + escapeHtml(match.ai_reasoning) + '</div>';
        }
        
        html += '<div class="sim-match-actions">';
        html += '<label><input type="checkbox" class="sim-select-checkbox" checked> Selected</label>';
        html += '<button type="button" class="button sim-insert-single-button">Insert Now</button>';
        html += '<a href="' + match.image_url + '" target="_blank" class="button sim-view-full">View Full ↗</a>';
        html += '</div>';
        html += '</div>';
        html += '</div>';
        html += '</div>';
        
        return html;
    }

    function createNoMatchHtml(heading) {
        let html = '<div class="sim-match-item no-match">';
        html += '<div class="sim-match-heading">';
        html += SimSvgIcons.close();
        html += '<span>' + heading.tag.toUpperCase() + ': ' + escapeHtml(heading.text) + '</span>';
        html += '</div>';
        html += '<div class="sim-no-match-warning">';
        html += SimSvgIcons.warning();
        html += '<span>' + simEditor.strings.noMatches + '</span>';
        html += '</div>';
        html += '</div>';
        
        return html;
    }

    function insertSingleImage() {
        const $item = $(this).closest('.sim-match-item');
        const imageId = $item.data('image-id');
        const headingPosition = $item.data('heading-position');
        
        console.log('SIM: Inserting single image', {imageId, headingPosition, postId: simEditor.postId});
        
        $(this).prop('disabled', true).text('Inserting...');
        
        // Show notice in modal
        $('.sim-results-state').hide();
        $('.sim-modal-body').html(
            '<div style="text-align: center; padding: 40px 20px;">' +
            '<div class="sim-loading-state">' +
            '<p style="font-size: 16px; margin-bottom: 15px;">Inserting image...</p>' +
            '<div class="sim-progress-bar"><div class="sim-progress-fill" style="width: 50%;"></div></div>' +
            '<p style="color: #666; margin-top: 15px;"><strong>Page will reload</strong> to show changes</p>' +
            '</div>' +
            '</div>'
        );
        
        $.ajax({
            url: simEditor.ajaxUrl,
            type: 'POST',
            data: {
                action: 'sim_insert_image',
                nonce: simEditor.nonce,
                post_id: simEditor.postId,
                image_id: imageId,
                heading_position: headingPosition
            },
            success: function(response) {
                console.log('SIM: Insert response', response);
                
                if (response.success) {
                    // Show debug info
                    if (response.data.debug) {
                        console.log('SIM DEBUG:', response.data.debug);
                        console.log('Content length: ' + response.data.debug.original_length + ' → ' + response.data.debug.new_length);
                        console.log('Image exists in DB: ' + response.data.debug.image_exists);
                    }
                    
                    // Show success then reload
                    $('.sim-modal-body').html(
                        '<div style="text-align: center; padding: 40px 20px;">' +
                        '<div class="sim-success-message" style="font-size: 18px; margin-bottom: 15px;">' +
                        '✓ Image inserted successfully!' +
                        '</div>' +
                        '<p style="color: #666;">Reloading page...</p>' +
                        '</div>'
                    );
                    
                    setTimeout(function() {
                        location.reload();
                    }, 800);
                } else {
                    console.error('SIM: Insert failed', response);
                    showError(response.data.message || 'Failed to insert image');
                }
            },
            error: function(xhr, status, error) {
                console.error('SIM: AJAX error', {xhr, status, error});
                showError('Failed to insert image. Check browser console for details.');
            }
        });
    }

    function insertAllSelected() {
        const insertions = [];
        
        $('.sim-match-item').each(function() {
            const $checkbox = $(this).find('.sim-select-checkbox');
            if ($checkbox.is(':checked')) {
                const imageId = $(this).data('image-id');
                const headingPosition = $(this).data('heading-position');
                
                if (imageId && headingPosition !== undefined) {
                    insertions.push({
                        image_id: imageId,
                        heading_position: headingPosition
                    });
                }
            }
        });
        
        if (insertions.length === 0) {
            alert('No images selected');
            return;
        }
        
        console.log('SIM: Inserting all', {count: insertions.length, insertions, postId: simEditor.postId});
        
        // Show notice in modal
        $('.sim-results-state').hide();
        $('.sim-modal-body').html(
            '<div style="text-align: center; padding: 40px 20px;">' +
            '<div class="sim-loading-state">' +
            '<p style="font-size: 16px; margin-bottom: 15px;">Inserting ' + insertions.length + ' images...</p>' +
            '<div class="sim-progress-bar"><div class="sim-progress-fill" style="width: 60%;"></div></div>' +
            '<p style="color: #666; margin-top: 15px;"><strong>Page will reload</strong> to show changes</p>' +
            '</div>' +
            '</div>'
        );
        
        $('.sim-insert-all-button').prop('disabled', true).hide();
        
        $.ajax({
            url: simEditor.ajaxUrl,
            type: 'POST',
            data: {
                action: 'sim_insert_all_images',
                nonce: simEditor.nonce,
                post_id: simEditor.postId,
                insertions: JSON.stringify(insertions)
            },
            success: function(response) {
                console.log('SIM: Bulk insert response', response);
                if (response.success) {
                    // Show success message then reload
                    $('.sim-modal-body').html(
                        '<div style="text-align: center; padding: 40px 20px;">' +
                        '<div class="sim-success-message" style="font-size: 18px; margin-bottom: 15px;">' +
                        '✓ Inserted ' + response.data.success_count + ' images successfully!' +
                        '</div>' +
                        '<p style="color: #666;">Reloading page...</p>' +
                        '</div>'
                    );
                    
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    console.error('SIM: Bulk insert failed', response);
                    showError(response.data.message || 'Failed to insert images');
                }
            },
            error: function(xhr, status, error) {
                console.error('SIM: Bulk AJAX error', {xhr, status, error});
                showError('Failed to insert images. Check browser console for details.');
            }
        });
    }


    function showSuccessMessage(message) {
        const $msg = $('<div class="sim-success-message">' + message + '</div>');
        $('.sim-modal-body').prepend($msg);
        setTimeout(function() {
            $msg.fadeOut(function() {
                $(this).remove();
            });
        }, 3000);
    }

    function showError(message) {
        $('.sim-loading-state').hide();
        $('.sim-results-state').hide();
        $('.sim-error-state').show();
        $('.sim-error-message').text(message);
    }

    function navigatePrev() {
        const $item = $(this).closest('.sim-match-item');
        const headingPosition = $item.data('heading-position');
        const allMatches = $item.data('all-matches');
        
        if (currentIndices[headingPosition] > 0) {
            currentIndices[headingPosition]--;
            updateCarouselDisplay($item, allMatches, currentIndices[headingPosition]);
        }
    }
    
    function navigateNext() {
        const $item = $(this).closest('.sim-match-item');
        const headingPosition = $item.data('heading-position');
        const allMatches = $item.data('all-matches');
        
        if (currentIndices[headingPosition] < allMatches.length - 1) {
            currentIndices[headingPosition]++;
            updateCarouselDisplay($item, allMatches, currentIndices[headingPosition]);
        }
    }
    
    function updateCarouselDisplay($item, matches, index) {
        const match = matches[index];
        const totalMatches = matches.length;
        
        // Add transitioning class for smooth fade
        const $preview = $item.find('.sim-image-preview');
        const $info = $item.find('.sim-image-info');
        
        $preview.addClass('sim-transitioning');
        $info.addClass('sim-transitioning');
        
        // Update image ID for insertion
        $item.data('image-id', match.image_id);
        
        // Update confidence score and styling IMMEDIATELY
        const confidenceClass = match.confidence_score >= 90 ? 'sim-confidence-high' :
                                match.confidence_score >= 70 ? 'sim-confidence-medium' :
                                'sim-confidence-low';
        
        $item.find('.sim-confidence-score')
            .removeClass('sim-confidence-high sim-confidence-medium sim-confidence-low')
            .addClass(confidenceClass);
        $item.find('.sim-confidence-value').text(match.confidence_score + '%');
        
        // Update title and filename IMMEDIATELY
        if (match.title) {
            if ($item.find('.sim-image-title').length === 0) {
                $item.find('.sim-image-filename').before(
                    '<div class="sim-filename sim-image-title"><strong>Image Title:</strong> <span class="sim-title-value">' + escapeHtml(match.title) + '</span></div>'
                );
            } else {
                $item.find('.sim-title-value').text(match.title);
            }
        } else {
            $item.find('.sim-image-title').remove();
        }
        $item.find('.sim-filename-value').text(match.filename);
        
        // Update AI reasoning IMMEDIATELY
        if (match.ai_reasoning) {
            if ($item.find('.sim-ai-reasoning').length === 0) {
                $item.find('.sim-image-filename').after(
                    '<div class="sim-ai-reasoning sim-reasoning-value">' + escapeHtml(match.ai_reasoning) + '</div>'
                );
            } else {
                $item.find('.sim-reasoning-value').text(match.ai_reasoning);
            }
        } else {
            $item.find('.sim-ai-reasoning').remove();
        }
        
        // Update View Full link IMMEDIATELY
        $item.find('.sim-view-full').attr('href', match.image_url);
        
        // Update carousel controls IMMEDIATELY
        $item.find('.sim-current-index').text(index + 1);
        
        // Show/hide best match badge IMMEDIATELY
        if (index === 0) {
            if ($item.find('.sim-best-match-badge').length === 0) {
                $item.find('.sim-carousel-counter strong').prepend('<span class="sim-best-match-badge">⭐ Best Match</span> ');
            }
        } else {
            $item.find('.sim-best-match-badge').remove();
        }
        
        // Enable/disable navigation buttons IMMEDIATELY
        $item.find('.sim-carousel-prev').prop('disabled', index === 0);
        $item.find('.sim-carousel-next').prop('disabled', index === totalMatches - 1);
        
        // Update image with smooth transition (slightly delayed for effect)
        setTimeout(function() {
            $preview.attr('src', match.image_url);
            
            // Remove transition class when image loads
            $preview.one('load', function() {
                $preview.removeClass('sim-transitioning');
                $info.removeClass('sim-transitioning');
            });
            
            // Fallback: remove class after short delay
            setTimeout(function() {
                $preview.removeClass('sim-transitioning');
                $info.removeClass('sim-transitioning');
            }, 150);
        }, 10);
        
        // Preload next image for instant navigation
        if (index + 1 < totalMatches) {
            preloadImage(matches[index + 1].image_url);
        }
        // Also preload previous if going backwards
        if (index - 1 >= 0) {
            preloadImage(matches[index - 1].image_url);
        }
    }
    
    function preloadImage(url) {
        // Create new Image object - browser will cache it
        const img = new Image();
        img.src = url;
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function handleCheckboxChange() {
        const $checkbox = $(this);
        const $label = $checkbox.parent();
        const isChecked = $checkbox.is(':checked');
        
        // Update the text based on checkbox state
        if (isChecked) {
            $label.contents().last()[0].textContent = ' Selected';
        } else {
            $label.contents().last()[0].textContent = ' Select';
        }
        
        // Update icon based on checkbox state
        const $matchItem = $checkbox.closest('.sim-match-item');
        const $iconContainer = $matchItem.find('.sim-match-heading');
        
        if (isChecked) {
            // Show checkmark
            $iconContainer.find('.sim-svg-icon').replaceWith(SimSvgIcons.check());
        } else {
            // Show X
            $iconContainer.find('.sim-svg-icon').replaceWith(SimSvgIcons.close());
        }
    }

})(jQuery);

