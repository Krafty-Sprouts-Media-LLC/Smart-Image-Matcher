/**
 * Filename: sim-editor.js
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.0.0
 * Last Modified: 12/10/2025
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
        $(document).on('click', '.sim-undo-button', undoInsertions);
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
            '<strong>' + matchedHeadings + ' matches found for ' + totalHeadings + ' headings</strong>'
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
        html += '<div class="sim-filename">Filename: ' + escapeHtml(match.filename) + '</div>';
        
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
                    $item.css('background', '#ecf7ed');
                    $item.find('.sim-insert-single-button').text('✓ Inserted').prop('disabled', true);
                    
                    showSuccessMessage(simEditor.strings.insertSuccess);
                    
                    // Auto-reload after 2 seconds to show the inserted image
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    console.error('SIM: Insert failed', response);
                    alert(response.data.message || 'Failed to insert image');
                    $item.find('.sim-insert-single-button').prop('disabled', false).text('Insert Now');
                }
            },
            error: function(xhr, status, error) {
                console.error('SIM: AJAX error', {xhr, status, error});
                alert(simEditor.strings.insertError + '\n\nCheck browser console for details.');
                $item.find('.sim-insert-single-button').prop('disabled', false).text('Insert Now');
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
        
        $('.sim-insert-all-button').prop('disabled', true).text('Inserting...');
        
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
                    showSuccessWithUndo(response.data.message, response.data.success_count);
                } else {
                    console.error('SIM: Bulk insert failed', response);
                    alert(response.data.message || 'Failed to insert images');
                    $('.sim-insert-all-button').prop('disabled', false).text('Insert All Selected');
                }
            },
            error: function(xhr, status, error) {
                console.error('SIM: Bulk AJAX error', {xhr, status, error});
                alert('Failed to insert images\n\nCheck browser console for details.');
                $('.sim-insert-all-button').prop('disabled', false).text('Insert All Selected');
            }
        });
    }

    function showSuccessWithUndo(message, count) {
        $('.sim-results-state').hide();
        $('.sim-loading-state').hide();
        $('.sim-error-state').hide();
        
        $('.sim-modal-body').html(
            '<div class="sim-success-message">' +
            '<strong>✓ ' + message + '</strong><br>' +
            '<small>Draft saved automatically</small>' +
            '</div>' +
            '<div style="margin: 20px 0;">' +
            '<p><strong>Page will auto-reload in <span id="sim-reload-countdown">10</span> seconds to show changes...</strong></p>' +
            '<button type="button" class="button button-large sim-undo-button">Undo All Insertions</button>' +
            '<div class="sim-undo-timer" style="margin-top: 10px;">Undo available for <span id="sim-countdown">10</span>s</div>' +
            '</div>' +
            '<div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">' +
            '<button type="button" class="button button-primary button-large sim-reload-now">Reload Now</button>' +
            '<button type="button" class="button sim-cancel-reload" style="margin-left: 10px;">Cancel Auto-Reload</button>' +
            '</div>'
        );
        
        $('.sim-modal-footer').html(
            '<button type="button" class="button sim-cancel-button">Close Without Reloading</button>'
        );
        
        let countdown = 10;
        let reloadCountdown = 10;
        let reloadTimerId;
        let cancelReload = false;
        
        // Undo timer
        undoTimerId = setInterval(function() {
            countdown--;
            $('#sim-countdown').text(countdown);
            
            if (countdown <= 0) {
                clearInterval(undoTimerId);
                $('.sim-undo-button').prop('disabled', true).text('Undo Expired');
                $('.sim-undo-timer').html('<em style="color: #999;">Undo is no longer available</em>');
            }
        }, 1000);
        
        // Auto-reload timer
        reloadTimerId = setInterval(function() {
            if (cancelReload) {
                clearInterval(reloadTimerId);
                return;
            }
            
            reloadCountdown--;
            $('#sim-reload-countdown').text(reloadCountdown);
            
            if (reloadCountdown <= 0) {
                clearInterval(reloadTimerId);
                clearInterval(undoTimerId);
                location.reload();
            }
        }, 1000);
        
        // Reload now button
        $(document).on('click', '.sim-reload-now', function() {
            clearInterval(reloadTimerId);
            clearInterval(undoTimerId);
            location.reload();
        });
        
        // Cancel auto-reload button
        $(document).on('click', '.sim-cancel-reload', function() {
            cancelReload = true;
            clearInterval(reloadTimerId);
            $('#sim-reload-countdown').parent().html('<em style="color: #999;">Auto-reload cancelled</em>');
            $(this).prop('disabled', true);
        });
    }

    function undoInsertions() {
        if (undoTimerId) {
            clearInterval(undoTimerId);
        }
        
        $('.sim-undo-button').prop('disabled', true).text('Undoing...');
        
        $.ajax({
            url: simEditor.ajaxUrl,
            type: 'POST',
            data: {
                action: 'sim_undo_insertions',
                nonce: simEditor.nonce,
                post_id: simEditor.postId
            },
            success: function(response) {
                if (response.success) {
                    $('.sim-modal-body').html(
                        '<div class="sim-success-message">✓ ' + response.data.message + '</div>' +
                        '<p>Reloading page to show changes...</p>'
                    );
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    alert(response.data.message);
                }
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

