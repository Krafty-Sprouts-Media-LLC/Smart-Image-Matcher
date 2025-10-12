/**
 * Filename: sim-editor.js
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.3.2
 * Last Modified: 12/10/2025
 * 
 * Simplified UX: No undo, just insert and reload with clear notices
 * Warning notice reminds users to review matches before inserting
 * Description: JavaScript for editor modal and image matching interface
 */

(function($) {
    'use strict';

    let currentMatches = [];
    let undoTimerId = null;

    $(document).ready(function() {
        $('#sim-open-modal, #sim-gutenberg-button').on('click', openModal);
        $('.sim-modal-close, .sim-cancel-button').on('click', closeModal);
        $('.sim-modal-overlay').on('click', closeModal);
        $('.sim-insert-all-button').on('click', insertAllSelected);
        
        $(document).on('click', '.sim-insert-single-button', insertSingleImage);
        $(document).on('click', '#sim-gutenberg-button', openModal);
        
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
                updateProgress(100);
                
                if (response.success) {
                    currentMatches = response.data.matches;
                    displayResults();
                } else {
                    showError(response.data.message);
                }
            },
            error: function(xhr, status, error) {
                showError('AJAX error: ' + error);
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
            'Uncheck any incorrect matches and verify images are relevant to their headings.' +
            '</div>'
        );
        
        const container = $('.sim-matches-container');
        container.empty();
        
        currentMatches.forEach(function(matchGroup) {
            const heading = matchGroup.heading;
            const matches = matchGroup.matches;
            
            if (matches.length > 0) {
                const topMatch = matches[0];
                const matchHtml = createMatchHtml(heading, topMatch);
                container.append(matchHtml);
            } else {
                const noMatchHtml = createNoMatchHtml(heading);
                container.append(noMatchHtml);
            }
        });
        
        $('.sim-insert-all-button').show();
    }

    function createMatchHtml(heading, match) {
        const confidenceClass = match.confidence_score >= 90 ? 'sim-confidence-high' :
                                match.confidence_score >= 70 ? 'sim-confidence-medium' :
                                'sim-confidence-low';
        
        let html = '<div class="sim-match-item" data-heading-position="' + heading.position + '" data-image-id="' + match.image_id + '">';
        html += '<div class="sim-match-heading">';
        html += '<span class="dashicons dashicons-yes"></span>';
        html += '<span>' + heading.tag.toUpperCase() + ': ' + escapeHtml(heading.text) + '</span>';
        html += '</div>';
        
        html += '<div class="sim-image-preview-container">';
        html += '<img src="' + match.image_url + '" alt="" class="sim-image-preview" />';
        html += '<div class="sim-image-info">';
        html += '<div class="sim-confidence-score ' + confidenceClass + '">';
        html += simEditor.strings.confidence + ': ' + match.confidence_score + '%';
        html += '</div>';
        
        if (match.title) {
            html += '<div class="sim-filename"><strong>Image Title:</strong> ' + escapeHtml(match.title) + '</div>';
        }
        html += '<div class="sim-filename"><strong>Filename:</strong> ' + escapeHtml(match.filename) + '</div>';
        
        if (match.ai_reasoning) {
            html += '<div class="sim-ai-reasoning">' + escapeHtml(match.ai_reasoning) + '</div>';
        }
        
        html += '<div class="sim-match-actions">';
        html += '<label><input type="checkbox" class="sim-select-checkbox" checked> Selected</label>';
        html += '<button type="button" class="button sim-insert-single-button">Insert Now</button>';
        html += '<a href="' + match.image_url + '" target="_blank" class="button">View Full ↗</a>';
        html += '</div>';
        html += '</div>';
        html += '</div>';
        html += '</div>';
        
        return html;
    }

    function createNoMatchHtml(heading) {
        let html = '<div class="sim-match-item no-match">';
        html += '<div class="sim-match-heading">';
        html += '<span class="dashicons dashicons-no"></span>';
        html += '<span>' + heading.tag.toUpperCase() + ': ' + escapeHtml(heading.text) + '</span>';
        html += '</div>';
        html += '<div class="sim-no-match-warning">';
        html += '<span class="dashicons dashicons-warning"></span>';
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

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

})(jQuery);

