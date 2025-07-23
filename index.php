<?php
// File: index.php

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/auth.php';

// If user is logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}
?>

<div class="hero-section bg-primary text-white py-5">
    <div class="container py-5">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h1 class="display-4 fw-bold mb-4">SmartSave Circle</h1>
                <p class="lead mb-4">A smarter way to save, borrow, and grow your money together with your community.</p>
                <div class="d-flex gap-3">
                    <a href="register.php" class="btn btn-light btn-lg">Get Started</a>
                    <a href="login.php" class="btn btn-outline-light btn-lg">Login</a>
                </div>
            </div>
            <div class="col-md-6">
                <img src="<?php echo IMG_PATH; ?>/savings-goal.png" alt="Savings illustration" class="img-fluid d-none d-md-block">
            </div>
        </div>
    </div>
</div>

<div class="features-section py-5 bg-light">
    <div class="container py-4">
        <div class="row text-center mb-5">
            <div class="col-md-12">
                <h2 class="fw-bold">How It Works</h2>
                <p class="lead text-muted">SmartSave Circle helps you achieve your financial goals</p>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body text-center">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3 mb-3 mx-auto" style="width: 80px; height: 80px;">
                            <i class="bi bi-piggy-bank fs-3"></i>
                        </div>
                        <h4>Personal Savings</h4>
                        <p class="text-muted">Set savings goals and track your progress with automated reminders and contributions.</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4 mb-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body text-center">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3 mb-3 mx-auto" style="width: 80px; height: 80px;">
                            <i class="bi bi-cash-coin fs-3"></i>
                        </div>
                        <h4>Micro-Loans</h4>
                        <p class="text-muted">Access affordable loans when you need them, with transparent terms and easy repayments.</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4 mb-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body text-center">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3 mb-3 mx-auto" style="width: 80px; height: 80px;">
                            <i class="bi bi-people fs-3"></i>
                        </div>
                        <h4>Community Circles</h4>
                        <p class="text-muted">Join or create savings circles with friends, family, or colleagues to save together.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="testimonials-section py-5">
    <div class="container py-4">
        <div class="row text-center mb-5">
            <div class="col-md-12">
                <h2 class="fw-bold">What Our Users Say</h2>
                <p class="lead text-muted">Join thousands of happy users managing their finances better</p>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="mb-3">
                            <i class="bi bi-quote fs-1 text-primary opacity-25"></i>
                        </div>
                        <p class="mb-4">SmartSave Circle helped me save for my new laptop in just 3 months. The reminders kept me on track!</p>
                        <div class="d-flex align-items-center">
                            <div class="bg-primary text-white rounded-circle me-3" style="width: 50px; height: 50px; line-height: 50px; text-align: center;">JD</div>
                            <div>
                                <h6 class="mb-0">John D.</h6>
                                <small class="text-muted">Kampala</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4 mb-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="mb-3">
                            <i class="bi bi-quote fs-1 text-primary opacity-25"></i>
                        </div>
                        <p class="mb-4">The loan I got helped me expand my small business. The repayment process was so easy!</p>
                        <div class="d-flex align-items-center">
                            <div class="bg-primary text-white rounded-circle me-3" style="width: 50px; height: 50px; line-height: 50px; text-align: center;">SM</div>
                            <div>
                                <h6 class="mb-0">Sarah M.</h6>
                                <small class="text-muted">Jinja</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4 mb-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="mb-3">
                            <i class="bi bi-quote fs-1 text-primary opacity-25"></i>
                        </div>
                        <p class="mb-4">Our women's group uses the savings circle feature to pool funds for our projects. It's been transformative!</p>
                        <div class="d-flex align-items-center">
                            <div class="bg-primary text-white rounded-circle me-3" style="width: 50px; height: 50px; line-height: 50px; text-align: center;">AK</div>
                            <div>
                                <h6 class="mb-0">Amina K.</h6>
                                <small class="text-muted">Mbale</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="cta-section bg-primary text-white py-5">
    <div class="container py-4 text-center">
        <h2 class="fw-bold mb-4">Ready to take control of your finances?</h2>
        <p class="lead mb-4">Join SmartSave Circle today and start your journey to financial freedom.</p>
        <a href="register.php" class="btn btn-light btn-lg px-4">Sign Up Now</a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
