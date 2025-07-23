// File: /assets/js/main.js

// Document ready function
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Check for unread notifications
    checkUnreadNotifications();
    
    // Set up event listeners for savings reminder button
    var reminderBtn = document.getElementById('reminder-btn');
    if (reminderBtn) {
        reminderBtn.addEventListener('click', function() {
            fetch('/api/reminders.php')
                .then(response => response.json())
                .then(data => {
                    if (data.reminders && data.reminders.length > 0) {
                        alert(`You have ${data.reminders.length} savings goals due today!`);
                    } else {
                        alert('No savings reminders at this time.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        });
    }
    
    // Handle form submissions with fetch API
    var forms = document.querySelectorAll('form[data-ajax="true"]');
    forms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var formData = new FormData(form);
            var submitBtn = form.querySelector('button[type="submit"]');
            var originalText = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
            
            fetch(form.action, {
                method: form.method,
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    } else {
                        // Show success message
                        alert(data.message || 'Action completed successfully');
                        if (data.reload) {
                            window.location.reload();
                        }
                    }
                } else {
                    alert(data.message || 'An error occurred');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });
    });
});

// Function to check for unread notifications
function checkUnreadNotifications() {
    fetch('/api/notifications.php?action=unread_count')
        .then(response => response.json())
        .then(data => {
            if (data.count > 0) {
                var badge = document.querySelector('.notification-badge');
                if (badge) {
                    badge.textContent = data.count;
                    badge.style.display = 'inline-block';
                }
            }
        })
        .catch(error => {
            console.error('Error checking notifications:', error);
        });
}

// Function to format currency
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'UGX'
    }).format(amount);
}

// Function to handle Bitnob payments
function processPayment(phone, amount, reference, narration) {
    return fetch('/api/bitnob.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'send_money',
            phone: phone,
            amount: amount,
            reference: reference,
            narration: narration
        })
    })
    .then(response => response.json());
}