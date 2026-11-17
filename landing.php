<!DOCTYPE html>
<html lang="sw">
<head>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-QJLXG4H5RY"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', 'G-QJLXG4H5RY');
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FIS LITE - Fabonic Information System | Mfumo wa Usimamizi wa Bidhaa kwa TZS 500</title>
    <meta name="description" content="FIS LITE - Mfumo kamili wa usimamizi wa bidhaa na mauzo. Unaanza kwa TZS 500 tu kwa siku.">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #0d6efd;
            --primary-dark: #0a58ca;
            --secondary: #1a1a2e;
            --accent: #ff6b35;
            --success: #198754;
            --light-bg: #f8f9fa;
            --gradient: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
        }
        
        * {
            font-family: 'Poppins', sans-serif;
            box-sizing: border-box;
        }
        
        html {
            scroll-behavior: smooth;
        }
        
        html, body {
            overflow-x: hidden;
            max-width: 100vw;
            width: 100%;
        }
        
        .container {
            max-width: 100%;
            padding-left: 16px;
            padding-right: 16px;
        }
        
        .row {
            margin-left: 0;
            margin-right: 0;
        }
        
        img, svg {
            max-width: 100%;
            height: auto;
        }
        
        /* =============================================
           MOBILE-FIRST BASE STYLES (< 576px)
           ============================================= */
        
        /* Language Switcher - Mobile */
        .lang-switcher {
            position: fixed;
            top: auto;
            bottom: 100px;
            left: 16px;
            right: auto;
            z-index: 9998;
            background: white;
            border-radius: 50px;
            padding: 4px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .lang-switcher .btn {
            border-radius: 50px;
            padding: 10px 14px;
            font-size: 0.8rem;
            font-weight: 600;
            min-height: 44px;
            min-width: 44px;
        }
        
        .lang-switcher .btn.active {
            background: var(--primary);
            color: white;
        }
        
        /* Navbar - Mobile First */
        .navbar {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
            padding: 10px 0;
        }
        
        .navbar-brand img {
            height: 36px;
        }
        
        .navbar-toggler {
            border: none;
            padding: 8px;
            min-height: 44px;
            min-width: 44px;
        }
        
        .navbar-toggler:focus {
            box-shadow: none;
        }
        
        .navbar-collapse {
            background: white;
            margin: 10px -16px 0;
            padding: 16px;
            border-top: 1px solid #eee;
        }
        
        .nav-link {
            font-weight: 500;
            color: var(--secondary) !important;
            padding: 12px 16px !important;
            margin: 0;
            border-radius: 8px;
            min-height: 48px;
            display: flex;
            align-items: center;
        }
        
        .nav-link:hover,
        .nav-link:active {
            color: var(--primary) !important;
            background: rgba(13, 110, 253, 0.05);
        }
        
        .navbar-nav .btn {
            margin: 8px 0 0;
            min-height: 48px;
            width: 100%;
            justify-content: center;
        }
        
        /* Hero Section - Mobile First */
        .hero {
            background: var(--gradient);
            min-height: 100svh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
            padding: 80px 0 40px;
        }
        
        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="0.5"/></svg>');
            background-size: 80px;
            opacity: 0.5;
        }
        
        .hero-content {
            position: relative;
            z-index: 1;
            text-align: center;
        }
        
        .hero h1 {
            font-size: clamp(1.75rem, 8vw, 3.5rem);
            font-weight: 700;
            color: white;
            margin-bottom: 12px;
            line-height: 1.2;
        }
        
        .hero .subtitle {
            font-size: clamp(0.95rem, 3.5vw, 1.3rem);
            color: rgba(255,255,255,0.9);
            margin-bottom: 20px;
            line-height: 1.5;
        }
        
        .hero .subtitle strong {
            display: block;
            margin-bottom: 4px;
        }
        
        .price-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--accent);
            color: white;
            padding: 12px 24px;
            border-radius: 50px;
            font-size: clamp(1rem, 4vw, 1.8rem);
            font-weight: 700;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
            box-shadow: 0 8px 25px rgba(255, 107, 53, 0.4);
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.03); }
        }
        
        .hero .text-white.mb-4 {
            font-size: clamp(0.9rem, 3vw, 1.1rem) !important;
            line-height: 1.6;
            margin-bottom: 24px !important;
        }
        
        .hero-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
            width: 100%;
        }
        
        .hero-buttons .btn {
            padding: 16px 24px;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 50px;
            min-height: 56px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-light {
            background: white;
            color: var(--primary);
        }
        
        .btn-outline-light:hover {
            background: white;
            color: var(--primary);
        }
        
        /* Hero Illustration - Mobile */
        .hero-illustration {
            display: none;
        }
        
        /* Screenshots Section - Mobile */
        .screenshots-section {
            background: var(--light-bg);
        }
        
        .screenshot-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            margin-bottom: 16px;
        }
        
        .screenshot-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }
        
        .screenshot-card img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            object-position: top;
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        
        .screenshot-card img:hover {
            transform: scale(1.02);
        }
        
        .screenshot-card .caption {
            padding: 16px;
            text-align: center;
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--secondary);
        }
        
        /* Lightbox Modal */
        .lightbox-modal .modal-dialog {
            max-width: 95vw;
            margin: 1rem auto;
        }
        
        .lightbox-modal .modal-content {
            background: transparent;
            border: none;
        }
        
        .lightbox-modal .modal-body {
            padding: 0;
            text-align: center;
        }
        
        .lightbox-modal .modal-body img {
            max-width: 100%;
            max-height: 80vh;
            border-radius: 8px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        
        .lightbox-modal .btn-close {
            position: absolute;
            top: -35px;
            right: 5px;
            background-color: white;
            border-radius: 50%;
            padding: 8px;
            opacity: 1;
            min-width: 44px;
            min-height: 44px;
        }
        
        /* Sections - Mobile First */
        .section {
            padding: 48px 0;
        }
        
        .section-title {
            font-size: clamp(1.5rem, 6vw, 2.5rem);
            font-weight: 700;
            color: var(--secondary);
            margin-bottom: 12px;
            line-height: 1.3;
        }
        
        .section-subtitle {
            color: #666;
            font-size: clamp(0.9rem, 3vw, 1.1rem);
            margin-bottom: 32px;
            line-height: 1.6;
        }
        
        /* Feature Cards - Mobile */
        .feature-card {
            background: white;
            border-radius: 16px;
            padding: 24px 20px;
            text-align: center;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            height: 100%;
            border: 1px solid #eee;
            margin-bottom: 16px;
        }
        
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.12);
        }
        
        .feature-icon {
            width: 64px;
            height: 64px;
            background: var(--gradient);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 1.5rem;
            color: white;
        }
        
        .feature-card h4 {
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--secondary);
            margin-bottom: 12px;
        }
        
        .feature-card p {
            color: #666;
            margin: 0;
            font-size: 0.9rem;
            line-height: 1.6;
        }
        
        /* Pricing Section - Mobile */
        .pricing-section {
            background: var(--light-bg);
        }
        
        .pricing-calculator {
            background: white;
            border-radius: 20px;
            padding: 24px 20px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.1);
            max-width: 100%;
            margin: 0 auto;
        }
        
        .pricing-calculator h3 {
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--secondary);
            margin-bottom: 24px;
            text-align: center;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--secondary);
            font-size: 0.9rem;
            margin-bottom: 8px;
        }
        
        .form-range {
            height: 8px;
            margin: 8px 0;
        }
        
        .range-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .total-price {
            background: var(--gradient);
            color: white;
            padding: 24px 20px;
            border-radius: 16px;
            text-align: center;
            margin-top: 24px;
        }
        
        .total-price .price {
            font-size: clamp(1.5rem, 6vw, 3rem);
            font-weight: 700;
            line-height: 1.2;
        }
        
        .total-price .period {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .pricing-note {
            font-size: 0.85rem;
            color: #666;
            margin-top: 12px;
            line-height: 1.5;
        }
        
        /* Benefits Section - Mobile */
        .benefit-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            margin-bottom: 24px;
            padding: 0 8px;
        }
        
        .benefit-icon {
            width: 56px;
            height: 56px;
            background: rgba(13, 110, 253, 0.1);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            flex-shrink: 0;
            color: var(--primary);
            font-size: 1.4rem;
        }
        
        .benefit-text h5 {
            font-weight: 600;
            font-size: 1rem;
            color: var(--secondary);
            margin-bottom: 8px;
        }
        
        .benefit-text p {
            color: #666;
            margin: 0;
            font-size: 0.9rem;
            line-height: 1.6;
        }
        
        /* Trial Form Section - Mobile */
        .trial-section {
            background: var(--secondary);
            color: white;
            padding: 48px 0;
        }
        
        .trial-form {
            background: white;
            border-radius: 20px;
            padding: 24px 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .trial-form h3 {
            color: var(--secondary);
            font-weight: 700;
            font-size: 1.25rem;
            margin-bottom: 24px;
            text-align: center;
        }
        
        .trial-form .form-control,
        .trial-form .form-select {
            padding: 14px 16px;
            border-radius: 10px;
            border: 2px solid #e9ecef;
            font-size: 1rem;
            min-height: 52px;
        }
        
        .trial-form .form-control:focus,
        .trial-form .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
        }
        
        .trial-form .btn-primary {
            padding: 16px 32px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 50px;
            background: var(--gradient);
            border: none;
            min-height: 56px;
            width: 100%;
        }
        
        .trial-info {
            margin-top: 32px;
            text-align: center;
        }
        
        .trial-info h4 {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 16px;
        }
        
        .trial-info ul {
            list-style: none;
            padding: 0;
            margin: 0;
            text-align: left;
        }
        
        .trial-info li {
            padding: 12px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            font-size: 0.95rem;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        
        .trial-info li i {
            color: var(--accent);
            flex-shrink: 0;
            margin-top: 2px;
        }
        
        /* Footer - Mobile */
        .footer {
            background: #0a0a1a;
            color: rgba(255,255,255,0.7);
            padding: 40px 0 24px;
            text-align: center;
        }
        
        .footer-logo img {
            height: 40px;
            margin-bottom: 16px;
        }
        
        .footer h5 {
            color: white;
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 16px;
            margin-top: 24px;
        }
        
        .footer p,
        .footer li {
            font-size: 0.9rem;
            line-height: 1.6;
        }
        
        .footer a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            padding: 4px 0;
            display: inline-block;
        }
        
        .footer a:hover {
            color: var(--primary);
        }
        
        .social-links {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-top: 16px;
        }
        
        .social-links a {
            display: inline-flex;
            width: 48px;
            height: 48px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            transition: all 0.3s;
        }
        
        .social-links a:hover {
            background: var(--primary);
            color: white;
        }
        
        /* WhatsApp Floating Button - Mobile */
        .whatsapp-float {
            position: fixed;
            bottom: 20px;
            right: 16px;
            width: 56px;
            height: 56px;
            background: #25D366;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.75rem;
            box-shadow: 0 4px 20px rgba(37, 211, 102, 0.4);
            z-index: 9999;
            animation: bounce 2s infinite;
            text-decoration: none;
        }
        
        .whatsapp-float:hover {
            background: #128C7E;
            color: white;
            transform: scale(1.1);
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }
        
        /* Alert Styles */
        .alert-container {
            position: fixed;
            top: 80px;
            left: 16px;
            right: 16px;
            z-index: 9999;
            max-width: 100%;
        }
        
        /* Form price estimate on mobile */
        #formPriceEstimate {
            padding: 16px !important;
        }
        
        #formPriceEstimate .fs-4 {
            font-size: 1.25rem !important;
        }
        
        /* =============================================
           SMALL TABLETS (>= 576px)
           ============================================= */
        @media (min-width: 576px) {
            .hero-buttons {
                flex-direction: row;
                flex-wrap: wrap;
                justify-content: center;
                gap: 12px;
            }
            
            .hero-buttons .btn {
                width: auto;
                min-width: 180px;
            }
            
            .screenshot-card img {
                height: 200px;
            }
            
            .pricing-calculator,
            .trial-form {
                padding: 32px 28px;
            }
            
            .alert-container {
                left: auto;
                right: 20px;
                max-width: 380px;
            }
        }
        
        /* =============================================
           TABLETS (>= 768px)
           ============================================= */
        @media (min-width: 768px) {
            .container {
                padding-left: 24px;
                padding-right: 24px;
            }
            
            /* Lang switcher moves to top-right */
            .lang-switcher {
                top: 90px;
                bottom: auto;
                left: auto;
                right: 20px;
            }
            
            .navbar {
                padding: 12px 0;
            }
            
            .navbar-brand img {
                height: 42px;
            }
            
            .navbar-collapse {
                background: transparent;
                margin: 0;
                padding: 0;
                border: none;
            }
            
            .nav-link {
                padding: 8px 12px !important;
                margin: 0 4px;
            }
            
            .navbar-nav .btn {
                margin: 0 0 0 8px;
                width: auto;
            }
            
            /* Hero - Tablet */
            .hero {
                padding: 100px 0 60px;
            }
            
            .hero-content {
                text-align: left;
            }
            
            .hero-buttons {
                justify-content: flex-start;
            }
            
            .hero-illustration {
                display: block;
                margin-top: 40px;
            }
            
            .hero-illustration svg {
                width: 100%;
                max-width: 320px;
                height: auto;
                margin: 0 auto;
                display: block;
            }
            
            /* Section spacing */
            .section {
                padding: 72px 0;
            }
            
            .section-subtitle {
                margin-bottom: 40px;
            }
            
            /* Feature cards */
            .feature-card {
                padding: 32px 24px;
                margin-bottom: 24px;
            }
            
            .feature-icon {
                width: 72px;
                height: 72px;
                font-size: 1.75rem;
            }
            
            /* Screenshot cards */
            .screenshot-card img {
                height: 220px;
            }
            
            .screenshot-card .caption {
                padding: 18px;
                font-size: 0.95rem;
            }
            
            /* Benefits - horizontal on tablet */
            .benefit-item {
                flex-direction: row;
                text-align: left;
                align-items: flex-start;
            }
            
            .benefit-icon {
                margin-bottom: 0;
                margin-right: 20px;
            }
            
            /* Trial info */
            .trial-info {
                margin-top: 40px;
                text-align: left;
            }
            
            .trial-info ul {
                text-align: left;
            }
            
            /* Footer */
            .footer {
                text-align: left;
                padding: 56px 0 28px;
            }
            
            .footer h5 {
                margin-top: 0;
            }
            
            .social-links {
                justify-content: flex-start;
            }
        }
        
        /* =============================================
           SMALL DESKTOP (>= 992px)
           ============================================= */
        @media (min-width: 992px) {
            .lang-switcher {
                top: 100px;
            }
            
            .navbar {
                padding: 15px 0;
            }
            
            .navbar-brand img {
                height: 45px;
            }
            
            .nav-link {
                margin: 0 8px;
            }
            
            /* Hero - Desktop */
            .hero {
                min-height: 100vh;
                padding-top: 80px;
            }
            
            .hero-illustration {
                margin-top: 0;
            }
            
            .hero-illustration svg {
                max-width: 400px;
            }
            
            /* Section spacing */
            .section {
                padding: 100px 0;
            }
            
            .section-subtitle {
                margin-bottom: 50px;
            }
            
            /* Feature cards */
            .feature-card {
                padding: 40px 30px;
            }
            
            .feature-icon {
                width: 80px;
                height: 80px;
                font-size: 2rem;
            }
            
            /* Screenshot cards */
            .screenshot-card img {
                height: 250px;
            }
            
            /* Pricing calculator */
            .pricing-calculator {
                padding: 48px;
                max-width: 600px;
            }
            
            .pricing-calculator h3 {
                font-size: 1.5rem;
            }
            
            .range-value {
                font-size: 1.5rem;
            }
            
            .total-price {
                padding: 30px;
            }
            
            /* Trial form */
            .trial-form {
                padding: 48px;
            }
            
            .trial-form h3 {
                font-size: 1.5rem;
            }
            
            /* Footer */
            .footer {
                padding: 60px 0 30px;
            }
            
            .footer-logo img {
                height: 50px;
            }
        }
        
        /* =============================================
           LARGE DESKTOP (>= 1200px)
           ============================================= */
        @media (min-width: 1200px) {
            .hero-illustration svg {
                max-width: 450px;
            }
            
            .nav-link {
                margin: 0 10px;
            }
        }
        
        /* =============================================
           TOUCH DEVICE OPTIMIZATIONS
           ============================================= */
        @media (hover: none) and (pointer: coarse) {
            .feature-card:hover,
            .screenshot-card:hover {
                transform: none;
            }
            
            .whatsapp-float:hover {
                transform: none;
            }
        }
        
        /* Reduce motion for users who prefer it */
        @media (prefers-reduced-motion: reduce) {
            .price-badge,
            .whatsapp-float {
                animation: none;
            }
            
            html {
                scroll-behavior: auto;
            }
            
            .feature-card,
            .screenshot-card {
                transition: none;
            }
        }
    </style>
</head>
<body>
    <!-- Language Switcher -->
    <div class="lang-switcher">
        <button class="btn btn-sm active" onclick="setLanguage('sw')" id="btnSw">SW</button>
        <button class="btn btn-sm" onclick="setLanguage('en')" id="btnEn">EN</button>
    </div>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand" href="#">
                <img src="https://fabonic.co.tz/wp-content/uploads/2021/12/logo.png" alt="Fabonic">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item"><a class="nav-link" href="#screenshots" data-sw="Picha" data-en="Screenshots">Picha</a></li>
                    <li class="nav-item"><a class="nav-link" href="#features" data-sw="Vipengele" data-en="Features">Vipengele</a></li>
                    <li class="nav-item"><a class="nav-link" href="#pricing" data-sw="Bei" data-en="Pricing">Bei</a></li>
                    <li class="nav-item"><a class="nav-link" href="#benefits" data-sw="Faida" data-en="Benefits">Faida</a></li>
                    <li class="nav-item"><a class="nav-link" href="#trial" data-sw="Jaribu Bure" data-en="Free Trial">Jaribu Bure</a></li>
                    <li class="nav-item">
                        <a class="btn btn-primary btn-sm ms-2" href="https://lite.fis.co.tz" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-1"></i> <span data-sw="Demo" data-en="Live Demo">Demo</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-7 hero-content">
                    <h1>FIS LITE</h1>
                    <p class="subtitle">
                        <strong>Fabonic Information System - LITE</strong><br>
                        <span data-sw="Mfumo Kamili wa Usimamizi wa Bidhaa na Mauzo kwa Biashara Ndogo na za Kati" 
                              data-en="Complete Inventory Management Solution for Small & Medium Businesses">
                            Mfumo Kamili wa Usimamizi wa Bidhaa na Mauzo kwa Biashara Ndogo na za Kati
                        </span>
                    </p>
                    <div class="price-badge">
                        <i class="bi bi-tag-fill me-2"></i>
                        <span data-sw="Kuanzia TZS 500/siku" data-en="Starting at TZS 500/day">Kuanzia TZS 500/siku</span>
                    </div>
                    <p class="text-white mb-4" style="font-size: 1.1rem;">
                        <span data-sw="Simamia bidhaa, mauzo, manunuzi, na ripoti kutoka popote. Inafanya kazi bila mtandao pia!" 
                              data-en="Manage inventory, sales, purchases, and reports from anywhere. Works offline too!">
                            Simamia bidhaa, mauzo, manunuzi, na ripoti kutoka popote. Inafanya kazi bila mtandao pia!
                        </span>
                    </p>
                    <div class="hero-buttons">
                        <a href="https://lite.fis.co.tz" target="_blank" class="btn btn-light">
                            <i class="bi bi-play-circle me-2"></i><span data-sw="Jaribu Demo" data-en="Try Demo">Jaribu Demo</span>
                        </a>
                        <a href="#trial" class="btn btn-outline-light">
                            <i class="bi bi-rocket-takeoff me-2"></i><span data-sw="Anza Bure" data-en="Start Free Trial">Anza Bure</span>
                        </a>
                    </div>
                </div>
                <div class="col-lg-5 text-center mt-5 mt-lg-0 hero-illustration">
                    <!-- Custom SVG Illustration -->
                    <svg viewBox="0 0 500 400" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <!-- Background Elements -->
                        <circle cx="250" cy="200" r="150" fill="rgba(255,255,255,0.1)"/>
                        <circle cx="250" cy="200" r="100" fill="rgba(255,255,255,0.1)"/>
                        
                        <!-- Warehouse/Box -->
                        <rect x="150" y="120" width="200" height="160" rx="15" fill="white" opacity="0.95"/>
                        <rect x="150" y="120" width="200" height="40" rx="15" fill="#0a58ca"/>
                        <text x="250" y="147" text-anchor="middle" fill="white" font-size="16" font-weight="600">FIS LITE</text>
                        
                        <!-- Dashboard Elements -->
                        <rect x="170" y="180" width="70" height="40" rx="8" fill="#e3f2fd"/>
                        <rect x="180" y="190" width="50" height="8" rx="4" fill="#0d6efd"/>
                        <rect x="180" y="205" width="35" height="8" rx="4" fill="#90caf9"/>
                        
                        <rect x="260" y="180" width="70" height="40" rx="8" fill="#e8f5e9"/>
                        <rect x="270" y="190" width="50" height="8" rx="4" fill="#4caf50"/>
                        <rect x="270" y="205" width="40" height="8" rx="4" fill="#a5d6a7"/>
                        
                        <rect x="170" y="230" width="160" height="35" rx="8" fill="#fff3e0"/>
                        <rect x="180" y="240" width="80" height="8" rx="4" fill="#ff9800"/>
                        <rect x="180" y="253" width="60" height="6" rx="3" fill="#ffcc80"/>
                        
                        <!-- Floating Elements -->
                        <circle cx="120" cy="150" r="25" fill="white" opacity="0.9"/>
                        <text x="120" y="155" text-anchor="middle" fill="#0d6efd" font-size="20">📦</text>
                        
                        <circle cx="380" cy="180" r="25" fill="white" opacity="0.9"/>
                        <text x="380" y="185" text-anchor="middle" fill="#4caf50" font-size="20">📊</text>
                        
                        <circle cx="100" cy="250" r="20" fill="white" opacity="0.9"/>
                        <text x="100" y="255" text-anchor="middle" fill="#ff9800" font-size="16">💰</text>
                        
                        <circle cx="400" cy="280" r="20" fill="white" opacity="0.9"/>
                        <text x="400" y="285" text-anchor="middle" fill="#9c27b0" font-size="16">📱</text>
                        
                        <!-- Connection Lines -->
                        <line x1="145" y1="150" x2="150" y2="160" stroke="white" stroke-width="2" stroke-dasharray="5,5" opacity="0.5"/>
                        <line x1="355" y1="180" x2="350" y2="180" stroke="white" stroke-width="2" stroke-dasharray="5,5" opacity="0.5"/>
                        
                        <!-- Mobile Phone -->
                        <rect x="380" y="100" width="60" height="100" rx="10" fill="white" opacity="0.95"/>
                        <rect x="385" y="115" width="50" height="70" rx="5" fill="#f5f5f5"/>
                        <circle cx="410" cy="195" r="5" fill="#e0e0e0"/>
                        <rect x="390" y="120" width="40" height="8" rx="4" fill="#0d6efd"/>
                        <rect x="390" y="133" width="30" height="6" rx="3" fill="#90caf9"/>
                        <rect x="390" y="145" width="35" height="6" rx="3" fill="#90caf9"/>
                        <rect x="390" y="160" width="40" height="20" rx="5" fill="#4caf50"/>
                        <text x="410" y="174" text-anchor="middle" fill="white" font-size="8" font-weight="600">SALE</text>
                    </svg>
                </div>
            </div>
        </div>
    </section>

    <!-- Screenshots Section -->
    <section class="section screenshots-section" id="screenshots">
        <div class="container">
            <div class="text-center">
                <h2 class="section-title" data-sw="Angalia Mfumo" data-en="See The System">Angalia Mfumo</h2>
                <p class="section-subtitle" data-sw="Picha halisi za mfumo unavyofanya kazi" data-en="Real screenshots of how the system works">Picha halisi za mfumo unavyofanya kazi</p>
            </div>
            <div class="row g-4">
                <div class="col-md-6 col-lg-3">
                    <div class="screenshot-card">
                        <img src="assets/images/screenshots/dashboard.jpg" alt="Dashboard" 
                             onclick="openLightbox(this.src, this.alt)"
                             onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="screenshot-placeholder" style="display:none; height:250px; background:linear-gradient(135deg,#0d6efd,#0a58ca); align-items:center; justify-content:center; flex-direction:column; color:white;">
                            <i class="bi bi-speedometer2" style="font-size:3rem;"></i>
                            <span style="margin-top:10px;">Dashboard</span>
                        </div>
                        <div class="caption" data-sw="Dashibodi Kuu" data-en="Main Dashboard">Dashibodi Kuu</div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="screenshot-card">
                        <img src="assets/images/screenshots/sales.jpg" alt="Quick Sales POS" 
                             onclick="openLightbox(this.src, this.alt)"
                             onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="screenshot-placeholder" style="display:none; height:250px; background:linear-gradient(135deg,#28a745,#1e7e34); align-items:center; justify-content:center; flex-direction:column; color:white;">
                            <i class="bi bi-cart-check" style="font-size:3rem;"></i>
                            <span style="margin-top:10px;">Mauzo</span>
                        </div>
                        <div class="caption" data-sw="Mauzo ya Haraka (POS)" data-en="Quick Sales (POS)">Mauzo ya Haraka (POS)</div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="screenshot-card">
                        <img src="assets/images/screenshots/stockreport.jpg" alt="Stock Report" 
                             onclick="openLightbox(this.src, this.alt)"
                             onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="screenshot-placeholder" style="display:none; height:250px; background:linear-gradient(135deg,#ffc107,#e0a800); align-items:center; justify-content:center; flex-direction:column; color:#333;">
                            <i class="bi bi-file-earmark-bar-graph" style="font-size:3rem;"></i>
                            <span style="margin-top:10px;">Ripoti</span>
                        </div>
                        <div class="caption" data-sw="Ripoti za Stock" data-en="Stock Reports">Ripoti za Stock</div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="screenshot-card">
                        <img src="assets/images/screenshots/mobile.jpg" alt="Mobile View" 
                             onclick="openLightbox(this.src, this.alt)"
                             onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="screenshot-placeholder" style="display:none; height:250px; background:linear-gradient(135deg,#6f42c1,#5a32a3); align-items:center; justify-content:center; flex-direction:column; color:white;">
                            <i class="bi bi-phone" style="font-size:3rem;"></i>
                            <span style="margin-top:10px;">Simu</span>
                        </div>
                        <div class="caption" data-sw="Imeundwa kwa Simu" data-en="Mobile-First Design">Imeundwa kwa Simu</div>
                    </div>
                </div>
            </div>
            
            <!-- Screenshot Modal for Zoom -->
            <div class="text-center mt-4">
                <a href="https://lite.fis.co.tz" target="_blank" class="btn btn-outline-primary btn-lg">
                    <i class="bi bi-play-circle me-2"></i>
                    <span data-sw="Jaribu Mwenyewe" data-en="Try It Yourself">Jaribu Mwenyewe</span>
                </a>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="section" id="features">
        <div class="container">
            <div class="text-center">
                <h2 class="section-title" data-sw="Vipengele Vyenye Nguvu" data-en="Powerful Features">Vipengele Vyenye Nguvu</h2>
                <p class="section-subtitle" data-sw="Kila kitu unachohitaji kusimamia bidhaa zako kwa ufanisi" data-en="Everything you need to manage your inventory efficiently">Kila kitu unachohitaji kusimamia bidhaa zako kwa ufanisi</p>
            </div>
            <div class="row g-4">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="bi bi-geo-alt"></i></div>
                        <h4 data-sw="Maeneo Mengi" data-en="Multi-Location Support">Maeneo Mengi</h4>
                        <p data-sw="Simamia ghala, maduka, au matawi mengi kutoka dashibodi moja" data-en="Manage multiple warehouses, stores, or branches from a single dashboard">Simamia ghala, maduka, au matawi mengi kutoka dashibodi moja</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="bi bi-cart-check"></i></div>
                        <h4 data-sw="Usimamizi wa Mauzo" data-en="Sales Management">Usimamizi wa Mauzo</h4>
                        <p data-sw="Mauzo ya haraka na ufuatiliaji wa wateja, punguzo, na njia za malipo" data-en="Quick POS-style sales with customer tracking, discounts, and payment options">Mauzo ya haraka na ufuatiliaji wa wateja, punguzo, na njia za malipo</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="bi bi-truck"></i></div>
                        <h4 data-sw="Usimamizi wa Manunuzi" data-en="Purchase Management">Usimamizi wa Manunuzi</h4>
                        <p data-sw="Fuatilia manunuzi, usimamizi wa wasambazaji, na masasisho ya stock moja kwa moja" data-en="Track purchases, supplier management, and automatic stock updates">Fuatilia manunuzi, usimamizi wa wasambazaji, na masasisho ya stock moja kwa moja</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="bi bi-box-seam"></i></div>
                        <h4 data-sw="Udhibiti wa Stock" data-en="Stock Control">Udhibiti wa Stock</h4>
                        <p data-sw="FIFO/LIFO/Average, ufuatiliaji wa batch, na marekebisho ya stock" data-en="FIFO/LIFO/Average costing, batch tracking, and stock adjustments">FIFO/LIFO/Average, ufuatiliaji wa batch, na marekebisho ya stock</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="bi bi-cloud-arrow-down"></i></div>
                        <h4 data-sw="Inafanya Kazi Bila Mtandao" data-en="Offline Sync">Inafanya Kazi Bila Mtandao</h4>
                        <p data-sw="Inafanya kazi bila internet! Data inasawazisha moja kwa moja ukiungana tena" data-en="Works without internet! Data syncs automatically when you're back online">Inafanya kazi bila internet! Data inasawazisha moja kwa moja ukiungana tena</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="bi bi-file-earmark-bar-graph"></i></div>
                        <h4 data-sw="Ripoti Kamili" data-en="Comprehensive Reports">Ripoti Kamili</h4>
                        <p data-sw="Ripoti za stock, faida/hasara, stock taking, na uchambuzi wa mwenendo" data-en="Stock reports, profit/loss, stock taking, and product movement analysis">Ripoti za stock, faida/hasara, stock taking, na uchambuzi wa mwenendo</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="bi bi-people"></i></div>
                        <h4 data-sw="Usimamizi wa Watumiaji" data-en="User Management">Usimamizi wa Watumiaji</h4>
                        <p data-sw="Udhibiti wa ufikiaji kwa majukumu na ruhusa kwa kila moduli" data-en="Role-based access control with granular permissions per module">Udhibiti wa ufikiaji kwa majukumu na ruhusa kwa kila moduli</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="bi bi-phone"></i></div>
                        <h4 data-sw="Imeundwa kwa Simu" data-en="Mobile-First Design">Imeundwa kwa Simu</h4>
                        <p data-sw="Imeboreshwa kwa simu na tablet na interface rahisi kugusa" data-en="Optimized for phones and tablets with touch-friendly interface">Imeboreshwa kwa simu na tablet na interface rahisi kugusa</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="bi bi-shield-check"></i></div>
                        <h4 data-sw="Salama na Kufuatiliwa" data-en="Secure & Audited">Salama na Kufuatiliwa</h4>
                        <p data-sw="Ufuatiliaji kamili wa shughuli, nywila zilizofichwa, na ulinzi wa CSRF" data-en="Complete audit trail, encrypted passwords, and CSRF protection">Ufuatiliaji kamili wa shughuli, nywila zilizofichwa, na ulinzi wa CSRF</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section class="section pricing-section" id="pricing">
        <div class="container">
            <div class="text-center">
                <h2 class="section-title" data-sw="Bei Rahisi na Wazi" data-en="Simple, Transparent Pricing">Bei Rahisi na Wazi</h2>
                <p class="section-subtitle" data-sw="Hakuna ada zilizofichwa. Lipa tu unachokitumia." data-en="No hidden fees. Pay only for what you use.">Hakuna ada zilizofichwa. Lipa tu unachokitumia.</p>
            </div>
            
            <div class="pricing-calculator">
                <h3 class="text-center"><i class="bi bi-calculator me-2"></i><span data-sw="Kokotoa Bei Yako" data-en="Calculate Your Price">Kokotoa Bei Yako</span></h3>
                
                <div class="mb-4">
                    <label class="form-label d-flex justify-content-between">
                        <span><i class="bi bi-geo-alt me-2"></i><span data-sw="Idadi ya Maeneo" data-en="Number of Locations">Idadi ya Maeneo</span></span>
                        <span class="range-value" id="locationsValue">1</span>
                    </label>
                    <input type="range" class="form-range" id="locationsRange" min="1" max="10" value="1">
                    <small class="text-muted" data-sw="Eneo la kwanza limejumuishwa kwenye bei ya msingi" data-en="First location included in base price">Eneo la kwanza limejumuishwa kwenye bei ya msingi</small>
                </div>
                
                <div class="mb-4">
                    <label class="form-label d-flex justify-content-between">
                        <span><i class="bi bi-people me-2"></i><span data-sw="Idadi ya Watumiaji" data-en="Number of Users">Idadi ya Watumiaji</span></span>
                        <span class="range-value" id="usersValue">5</span>
                    </label>
                    <input type="range" class="form-range" id="usersRange" min="5" max="100" value="5" step="5">
                    <small class="text-muted" data-sw="Watumiaji 5 wa kwanza wamejumuishwa kwenye bei ya msingi" data-en="First 5 users included in base price">Watumiaji 5 wa kwanza wamejumuishwa kwenye bei ya msingi</small>
                </div>
                
                <div class="total-price">
                    <div class="price">TZS <span id="totalPrice">15,000</span></div>
                    <div class="period" data-sw="kwa mwezi" data-en="per month">kwa mwezi</div>
                </div>
                
                <p class="pricing-note text-center">
                    <i class="bi bi-info-circle me-1"></i>
                    <span data-sw="Msingi: TZS 500/siku (15,000/mwezi) inajumuisha eneo 1 + watumiaji 5 | Eneo la ziada: TZS 500/siku | Watumiaji 5 wa ziada: TZS 500/siku" 
                          data-en="Base: TZS 500/day (15,000/month) includes 1 location + 5 users | Extra location: TZS 500/day | Extra 5 users: TZS 500/day">
                        Msingi: TZS 500/siku (15,000/mwezi) inajumuisha eneo 1 + watumiaji 5 | Eneo la ziada: TZS 500/siku | Watumiaji 5 wa ziada: TZS 500/siku
                    </span>
                </p>
                
                <div class="text-center mt-4">
                    <a href="#trial" class="btn btn-primary btn-lg">
                        <i class="bi bi-gift me-2"></i><span data-sw="Anza Majaribio ya Siku 14 Bure" data-en="Start 14-Day Free Trial">Anza Majaribio ya Siku 14 Bure</span>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Benefits Section -->
    <section class="section" id="benefits">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 mb-5 mb-lg-0">
                    <h2 class="section-title" data-sw="Kwa Nini Uchague FIS LITE?" data-en="Why Choose FIS LITE?">Kwa Nini Uchague FIS LITE?</h2>
                    <p class="section-subtitle mb-4" data-sw="Imeundwa kwa biashara za Tanzania, bei inayofaa kila mtu" data-en="Built for Tanzanian businesses, priced for everyone">Imeundwa kwa biashara za Tanzania, bei inayofaa kila mtu</p>
                    
                    <div class="benefit-item">
                        <div class="benefit-icon"><i class="bi bi-currency-dollar"></i></div>
                        <div class="benefit-text">
                            <h5 data-sw="Thamani Isiyoshindwa" data-en="Unbeatable Value">Thamani Isiyoshindwa</h5>
                            <p data-sw="Pata mfumo kamili kwa TZS 500 tu kwa siku - chini ya kikombe cha chai!" data-en="Get a complete system for just TZS 500/day - less than a cup of coffee!">Pata mfumo kamili kwa TZS 500 tu kwa siku - chini ya kikombe cha chai!</p>
                        </div>
                    </div>
                    
                    <div class="benefit-item">
                        <div class="benefit-icon"><i class="bi bi-clock-history"></i></div>
                        <div class="benefit-text">
                            <h5 data-sw="Usanidi wa Haraka" data-en="Quick Setup">Usanidi wa Haraka</h5>
                            <p data-sw="Anza kusimamia bidhaa ndani ya dakika, sio siku. Kuingiza kutoka Excel ni rahisi." data-en="Start managing inventory in minutes, not days. Easy import from Excel.">Anza kusimamia bidhaa ndani ya dakika, sio siku. Kuingiza kutoka Excel ni rahisi.</p>
                        </div>
                    </div>
                    
                    <div class="benefit-item">
                        <div class="benefit-icon"><i class="bi bi-wifi-off"></i></div>
                        <div class="benefit-text">
                            <h5 data-sw="Inafanya Kazi Bila Mtandao" data-en="Works Offline">Inafanya Kazi Bila Mtandao</h5>
                            <p data-sw="Hakuna internet? Hakuna shida! Endelea kufanya kazi na usawazishe ukiunganishwa." data-en="No internet? No problem! Continue working and sync when connected.">Hakuna internet? Hakuna shida! Endelea kufanya kazi na usawazishe ukiunganishwa.</p>
                        </div>
                    </div>
                    
                    <div class="benefit-item">
                        <div class="benefit-icon"><i class="bi bi-headset"></i></div>
                        <div class="benefit-text">
                            <h5 data-sw="Msaada wa Ndani" data-en="Local Support">Msaada wa Ndani</h5>
                            <p data-sw="Timu ya msaada ya Tanzania inapatikana kupitia WhatsApp na simu." data-en="Tanzanian-based support team available via WhatsApp and phone.">Timu ya msaada ya Tanzania inapatikana kupitia WhatsApp na simu.</p>
                        </div>
                    </div>
                    
                    <div class="benefit-item">
                        <div class="benefit-icon"><i class="bi bi-graph-up-arrow"></i></div>
                        <div class="benefit-text">
                            <h5 data-sw="Kukua Nawe" data-en="Grow With You">Kukua Nawe</h5>
                            <p data-sw="Ongeza maeneo na watumiaji biashara yako inavyopanuka. Hakuna vikomo." data-en="Add locations and users as your business expands. No limits.">Ongeza maeneo na watumiaji biashara yako inavyopanuka. Hakuna vikomo.</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="text-center">
                        <!-- Growth SVG Illustration -->
                        <svg viewBox="0 0 500 400" fill="none" xmlns="http://www.w3.org/2000/svg" style="max-width: 450px;">
                            <!-- Background -->
                            <rect x="50" y="50" width="400" height="300" rx="20" fill="#f8f9fa"/>
                            
                            <!-- Chart Lines -->
                            <line x1="100" y1="280" x2="400" y2="280" stroke="#dee2e6" stroke-width="2"/>
                            <line x1="100" y1="230" x2="400" y2="230" stroke="#dee2e6" stroke-width="1" stroke-dasharray="5,5"/>
                            <line x1="100" y1="180" x2="400" y2="180" stroke="#dee2e6" stroke-width="1" stroke-dasharray="5,5"/>
                            <line x1="100" y1="130" x2="400" y2="130" stroke="#dee2e6" stroke-width="1" stroke-dasharray="5,5"/>
                            
                            <!-- Growth Line -->
                            <path d="M 120 260 Q 180 240 220 200 T 300 150 T 380 90" stroke="#0d6efd" stroke-width="4" fill="none" stroke-linecap="round"/>
                            
                            <!-- Data Points -->
                            <circle cx="120" cy="260" r="8" fill="#0d6efd"/>
                            <circle cx="220" cy="200" r="8" fill="#0d6efd"/>
                            <circle cx="300" cy="150" r="8" fill="#0d6efd"/>
                            <circle cx="380" cy="90" r="8" fill="#0d6efd"/>
                            
                            <!-- Arrow Up -->
                            <path d="M 380 90 L 390 100 M 380 90 L 370 100" stroke="#0d6efd" stroke-width="3" stroke-linecap="round"/>
                            
                            <!-- Bars -->
                            <rect x="130" y="240" width="30" height="40" rx="5" fill="#28a745" opacity="0.8"/>
                            <rect x="190" y="210" width="30" height="70" rx="5" fill="#28a745" opacity="0.8"/>
                            <rect x="250" y="180" width="30" height="100" rx="5" fill="#28a745" opacity="0.8"/>
                            <rect x="310" y="140" width="30" height="140" rx="5" fill="#28a745" opacity="0.8"/>
                            <rect x="370" y="100" width="30" height="180" rx="5" fill="#28a745" opacity="0.8"/>
                            
                            <!-- Labels -->
                            <text x="250" y="330" text-anchor="middle" fill="#333" font-size="16" font-weight="600">📈 Biashara Yako Inakua!</text>
                        </svg>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Trial Form Section -->
    <section class="section trial-section" id="trial">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-5 mb-5 mb-lg-0">
                    <h2 class="section-title text-white" data-sw="Anza Majaribio Yako ya Bure Leo" data-en="Start Your Free Trial Today">Anza Majaribio Yako ya Bure Leo</h2>
                    <p class="text-white-50 mb-4" data-sw="Siku 14, hakuna kadi ya benki inayohitajika. Ufikiaji kamili wa vipengele vyote." data-en="14 days, no credit card required. Full access to all features.">Siku 14, hakuna kadi ya benki inayohitajika. Ufikiaji kamili wa vipengele vyote.</p>
                    
                    <div class="trial-info">
                        <h4 data-sw="Kinachojumuishwa katika Majaribio:" data-en="What's Included in Trial:">Kinachojumuishwa katika Majaribio:</h4>
                        <ul>
                            <li><i class="bi bi-check2-circle"></i> <span data-sw="Ufikiaji kamili wa vipengele vyote" data-en="Full access to all features">Ufikiaji kamili wa vipengele vyote</span></li>
                            <li><i class="bi bi-check2-circle"></i> <span data-sw="Hadi maeneo 3" data-en="Up to 3 locations">Hadi maeneo 3</span></li>
                            <li><i class="bi bi-check2-circle"></i> <span data-sw="Hadi watumiaji 5" data-en="Up to 5 users">Hadi watumiaji 5</span></li>
                            <li><i class="bi bi-check2-circle"></i> <span data-sw="Msaada wa kuingiza data bure" data-en="Free data import assistance">Msaada wa kuingiza data bure</span></li>
                            <li><i class="bi bi-check2-circle"></i> <span data-sw="Msaada wa WhatsApp" data-en="WhatsApp support">Msaada wa WhatsApp</span></li>
                            <li><i class="bi bi-check2-circle"></i> <span data-sw="Hakuna wajibu wa kuendelea" data-en="No obligation to continue">Hakuna wajibu wa kuendelea</span></li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="trial-form">
                        <h3 class="text-center"><i class="bi bi-rocket-takeoff me-2"></i><span data-sw="Omba Majaribio ya Bure" data-en="Request Free Trial">Omba Majaribio ya Bure</span></h3>
                        
                        <div id="alertContainer"></div>
                        
                        <form id="trialForm">
                            <!-- Honeypot field - hidden from users, catches bots -->
                            <div style="position: absolute; left: -9999px;" aria-hidden="true">
                                <input type="text" name="website_url" tabindex="-1" autocomplete="off">
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" data-sw="Jina Kamili *" data-en="Full Name *">Jina Kamili *</label>
                                    <input type="text" class="form-control" name="fullname" required 
                                           data-placeholder-sw="Jina lako kamili" data-placeholder-en="Your full name"
                                           placeholder="Jina lako kamili">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" data-sw="Jina la Biashara *" data-en="Business Name *">Jina la Biashara *</label>
                                    <input type="text" class="form-control" name="business" id="businessName" required 
                                           data-placeholder-sw="Jina la biashara yako" data-placeholder-en="Your business name"
                                           placeholder="Jina la biashara yako">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label" data-sw="Subdomain ya Mfumo *" data-en="System Subdomain *">Subdomain ya Mfumo *</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="subdomain" id="subdomainInput" required
                                           pattern="[a-z][a-z0-9]*" minlength="3" maxlength="20"
                                           data-placeholder-sw="jinalako" data-placeholder-en="yourname"
                                           placeholder="jinalako" style="text-transform: lowercase;">
                                    <span class="input-group-text" style="background: #e9ecef; font-weight: 500;">.fis.co.tz</span>
                                </div>
                                <div id="subdomainFeedback" class="mt-2" style="display: none;"></div>
                                <small class="text-muted" data-sw="Herufi ndogo na nambari tu (mf. dukalangu)" data-en="Lowercase letters and numbers only (e.g. myshop)">Herufi ndogo na nambari tu (mf. dukalangu)</small>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" data-sw="Barua Pepe *" data-en="Email Address *">Barua Pepe *</label>
                                    <input type="email" class="form-control" name="email" required 
                                           placeholder="barua@mfano.com">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" data-sw="Nambari ya Simu *" data-en="Phone Number *">Nambari ya Simu *</label>
                                    <input type="tel" class="form-control" name="phone" required 
                                           placeholder="+255 7XX XXX XXX">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" data-sw="Idadi ya Maeneo" data-en="Number of Locations">Idadi ya Maeneo</label>
                                    <select class="form-select" name="locations" id="formLocations">
                                        <option value="1" data-sw="Eneo 1" data-en="1 Location">Eneo 1</option>
                                        <option value="2" data-sw="Maeneo 2" data-en="2 Locations">Maeneo 2</option>
                                        <option value="3" data-sw="Maeneo 3" data-en="3 Locations">Maeneo 3</option>
                                        <option value="4" data-sw="Maeneo 4" data-en="4 Locations">Maeneo 4</option>
                                        <option value="5" data-sw="Maeneo 5+" data-en="5+ Locations">Maeneo 5+</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" data-sw="Idadi ya Watumiaji" data-en="Number of Users">Idadi ya Watumiaji</label>
                                    <select class="form-select" name="users" id="formUsers">
                                        <option value="5" selected data-sw="Watumiaji 5 (msingi)" data-en="5 Users (default)">Watumiaji 5 (msingi)</option>
                                        <option value="10" data-sw="Watumiaji 10" data-en="10 Users">Watumiaji 10</option>
                                        <option value="15" data-sw="Watumiaji 15" data-en="15 Users">Watumiaji 15</option>
                                        <option value="20" data-sw="Watumiaji 20" data-en="20 Users">Watumiaji 20</option>
                                        <option value="50" data-sw="Watumiaji 50" data-en="50 Users">Watumiaji 50</option>
                                        <option value="100" data-sw="Watumiaji 100" data-en="100 Users">Watumiaji 100</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label" data-sw="Aina ya Biashara" data-en="Business Type">Aina ya Biashara</label>
                                <select class="form-select" name="business_type">
                                    <option value="" data-sw="Chagua aina ya biashara yako" data-en="Select your business type">Chagua aina ya biashara yako</option>
                                    <option value="retail" data-sw="Duka la Rejareja" data-en="Retail Shop">Duka la Rejareja</option>
                                    <option value="wholesale" data-sw="Jumla" data-en="Wholesale">Jumla</option>
                                    <option value="pharmacy" data-sw="Duka la Dawa" data-en="Pharmacy">Duka la Dawa</option>
                                    <option value="restaurant" data-sw="Mgahawa" data-en="Restaurant">Mgahawa</option>
                                    <option value="electronics" data-sw="Vifaa vya Elektroniki" data-en="Electronics">Vifaa vya Elektroniki</option>
                                    <option value="hardware" data-sw="Duka la Vifaa" data-en="Hardware Store">Duka la Vifaa</option>
                                    <option value="supermarket" data-sw="Supermarket" data-en="Supermarket">Supermarket</option>
                                    <option value="other" data-sw="Nyingine" data-en="Other">Nyingine</option>
                                </select>
                            </div>
                            
                            <!-- Estimated Price Display -->
                            <div class="bg-light rounded p-3 mb-4" id="formPriceEstimate">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-semibold" data-sw="Gharama ya Mwezi inayokadiriwa:" data-en="Estimated Monthly Cost:">Gharama ya Mwezi inayokadiriwa:</span>
                                    <span class="text-primary fw-bold fs-4" id="formTotalPrice">TZS 15,000</span>
                                </div>
                                <small class="text-muted" data-sw="Kulingana na maeneo na watumiaji waliochaguliwa" data-en="Based on selected locations and users">Kulingana na maeneo na watumiaji waliochaguliwa</small>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label" data-sw="Ujumbe (Hiari)" data-en="Message (Optional)">Ujumbe (Hiari)</label>
                                <textarea class="form-control" name="message" rows="3" 
                                          data-placeholder-sw="Tuambie kuhusu mahitaji ya biashara yako..." 
                                          data-placeholder-en="Tell us about your business needs..."
                                          placeholder="Tuambie kuhusu mahitaji ya biashara yako..."></textarea>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="bi bi-send me-2"></i><span data-sw="Omba Majaribio ya Bure" data-en="Request Free Trial">Omba Majaribio ya Bure</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <div class="footer-logo">
                        <img src="https://fabonic.co.tz/wp-content/uploads/2021/12/logo.png" alt="Fabonic">
                    </div>
                    <p data-sw="FIS LITE na Fabonic Ltd - Mfumo kamili wa usimamizi wa bidhaa kwa biashara za Tanzania." 
                       data-en="FIS LITE by Fabonic Ltd - Comprehensive inventory management solution for Tanzanian businesses.">
                        FIS LITE na Fabonic Ltd - Mfumo kamili wa usimamizi wa bidhaa kwa biashara za Tanzania.
                    </p>
                    <div class="social-links">
                        <a href="https://www.facebook.com/fabonic/" target="_blank"><i class="bi bi-facebook"></i></a>
                        <a href="https://www.instagram.com/fabonic/" target="_blank"><i class="bi bi-instagram"></i></a>
                        <a href="https://wa.me/255786110021" target="_blank"><i class="bi bi-whatsapp"></i></a>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 mb-4">
                    <h5 data-sw="Viungo vya Haraka" data-en="Quick Links">Viungo vya Haraka</h5>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="#features" data-sw="Vipengele" data-en="Features">Vipengele</a></li>
                        <li class="mb-2"><a href="#pricing" data-sw="Bei" data-en="Pricing">Bei</a></li>
                        <li class="mb-2"><a href="#trial" data-sw="Majaribio ya Bure" data-en="Free Trial">Majaribio ya Bure</a></li>
                        <li class="mb-2"><a href="https://lite.fis.co.tz" target="_blank">Demo</a></li>
                    </ul>
                </div>
                <div class="col-lg-3 col-md-4 mb-4">
                    <h5 data-sw="Wasiliana Nasi" data-en="Contact Us">Wasiliana Nasi</h5>
                    <ul class="list-unstyled">
                        <li class="mb-2"><i class="bi bi-geo-alt me-2"></i>Ghadeer Towers, Indira Gandhi Street, DSM</li>
                        <li class="mb-2"><i class="bi bi-phone me-2"></i><a href="tel:+255786110021">+255 786 110 021</a></li>
                        <li class="mb-2"><i class="bi bi-envelope me-2"></i><a href="mailto:info@fabonic.co.tz">info@fabonic.co.tz</a></li>
                    </ul>
                </div>
                <div class="col-lg-3 col-md-4 mb-4">
                    <h5 data-sw="Bidhaa Nyingine" data-en="Other Products">Bidhaa Nyingine</h5>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="https://fabapps.co.tz/pharmacy-software/" target="_blank" data-sw="Software ya Duka la Dawa" data-en="Pharmacy Software">Software ya Duka la Dawa</a></li>
                        <li class="mb-2"><a href="https://fabapps.co.tz/hospital-management-system/" target="_blank" data-sw="Usimamizi wa Hospitali" data-en="Hospital Management">Usimamizi wa Hospitali</a></li>
                        <li class="mb-2"><a href="https://fabapps.co.tz/point-of-sales-software/" target="_blank" data-sw="Mfumo wa POS" data-en="POS System">Mfumo wa POS</a></li>
                        <li class="mb-2"><a href="https://fabonic.co.tz" target="_blank" data-sw="Tembelea Fabonic.co.tz" data-en="Visit Fabonic.co.tz">Tembelea Fabonic.co.tz</a></li>
                    </ul>
                </div>
            </div>
            <hr class="my-4" style="border-color: rgba(255,255,255,0.1);">
            <div class="text-center">
                <p class="mb-0">&copy; <?php echo date('Y'); ?> Fabonic Ltd. <span data-sw="Haki zote zimehifadhiwa." data-en="All rights reserved.">Haki zote zimehifadhiwa.</span></p>
            </div>
        </div>
    </footer>

    <!-- WhatsApp Floating Button -->
    <a href="https://wa.me/255786110021?text=Habari,%20Ninapenda%20kupata%20taarifa%20zaidi%20kuhusu%20FIS%20LITE" 
       class="whatsapp-float" target="_blank" title="Chat on WhatsApp">
        <i class="bi bi-whatsapp"></i>
    </a>
    
    <!-- Lightbox Modal -->
    <div class="modal fade lightbox-modal" id="screenshotModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="modal-body">
                    <img src="" alt="" id="lightboxImage">
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Lightbox Function
        function openLightbox(src, alt) {
            document.getElementById('lightboxImage').src = src;
            document.getElementById('lightboxImage').alt = alt;
            const modal = new bootstrap.Modal(document.getElementById('screenshotModal'));
            modal.show();
        }
        
        // Language Management
        let currentLang = localStorage.getItem('fis_lang') || 'sw';
        
        function setLanguage(lang) {
            currentLang = lang;
            localStorage.setItem('fis_lang', lang);
            
            // Update button states
            document.getElementById('btnSw').classList.toggle('active', lang === 'sw');
            document.getElementById('btnEn').classList.toggle('active', lang === 'en');
            
            // Update all translatable elements
            document.querySelectorAll('[data-sw], [data-en]').forEach(el => {
                const text = el.getAttribute('data-' + lang);
                if (text) {
                    el.textContent = text;
                }
            });
            
            // Update placeholders
            document.querySelectorAll('[data-placeholder-sw], [data-placeholder-en]').forEach(el => {
                const placeholder = el.getAttribute('data-placeholder-' + lang);
                if (placeholder) {
                    el.placeholder = placeholder;
                }
            });
            
            // Update select options
            document.querySelectorAll('select option[data-sw], select option[data-en]').forEach(option => {
                const text = option.getAttribute('data-' + lang);
                if (text) {
                    option.textContent = text;
                }
            });
            
            // Update HTML lang attribute
            document.documentElement.lang = lang;
            
            // Update WhatsApp message
            const waButton = document.querySelector('.whatsapp-float');
            if (waButton) {
                const waMessage = lang === 'sw' 
                    ? 'Habari,%20Ninapenda%20kupata%20taarifa%20zaidi%20kuhusu%20FIS%20LITE'
                    : 'Hello,%20I\'m%20interested%20in%20FIS%20LITE%20inventory%20system';
                waButton.href = 'https://wa.me/255786110021?text=' + waMessage;
            }
        }
        
        // Initialize language on page load
        document.addEventListener('DOMContentLoaded', function() {
            setLanguage(currentLang);
        });
        
        // Pricing Calculator
        const locationsRange = document.getElementById('locationsRange');
        const usersRange = document.getElementById('usersRange');
        const locationsValue = document.getElementById('locationsValue');
        const usersValue = document.getElementById('usersValue');
        const totalPrice = document.getElementById('totalPrice');
        
        function calculatePrice() {
            const locations = parseInt(locationsRange.value);
            const users = parseInt(usersRange.value);
            
            const basePricePerMonth = 15000; // 500/day x 30 days, includes 1 location + 5 users
            const additionalLocations = Math.max(0, locations - 1);
            const additionalUserBlocks = Math.max(0, Math.floor((users - 5) / 5)); // Each 5 extra users = 1 unit
            
            const total = basePricePerMonth + (additionalLocations * 15000) + (additionalUserBlocks * 15000);
            
            locationsValue.textContent = locations;
            usersValue.textContent = users;
            totalPrice.textContent = total.toLocaleString();
            
            // Sync calculator values to form fields
            syncCalculatorToForm(locations, users);
        }
        
        function syncCalculatorToForm(locations, users) {
            // Map locations: calculator allows 1-5, form has options 1,2,3,4,5
            if (formLocations) {
                formLocations.value = Math.min(locations, 5);
            }
            
            // Map users: calculator allows any value, form has 5,10,15,20,50,100
            if (formUsers) {
                const userOptions = [5, 10, 15, 20, 50, 100];
                // Find closest option that's >= calculator value
                let closestOption = userOptions.find(opt => opt >= users) || userOptions[userOptions.length - 1];
                formUsers.value = closestOption;
            }
            
            // Recalculate form price
            if (typeof calculateFormPrice === 'function') {
                calculateFormPrice();
            }
        }
        
        locationsRange.addEventListener('input', calculatePrice);
        usersRange.addEventListener('input', calculatePrice);
        
        // Form Pricing Calculator
        const formLocations = document.getElementById('formLocations');
        const formUsers = document.getElementById('formUsers');
        const formTotalPrice = document.getElementById('formTotalPrice');
        
        function calculateFormPrice() {
            let locations = parseInt(formLocations.value);
            let users = parseInt(formUsers.value);
            
            const basePricePerMonth = 15000; // 500/day x 30 days, includes 1 location + 5 users
            const additionalLocations = Math.max(0, locations - 1);
            const additionalUserBlocks = Math.max(0, Math.floor((users - 5) / 5)); // Each 5 extra users = 1 unit
            
            const total = basePricePerMonth + (additionalLocations * 15000) + (additionalUserBlocks * 15000);
            
            formTotalPrice.textContent = 'TZS ' + total.toLocaleString();
        }
        
        formLocations.addEventListener('change', calculateFormPrice);
        formUsers.addEventListener('change', calculateFormPrice);
        
        // Subdomain Management
        const businessNameInput = document.getElementById('businessName');
        const subdomainInput = document.getElementById('subdomainInput');
        const subdomainFeedback = document.getElementById('subdomainFeedback');
        let subdomainManuallyEdited = false;
        let subdomainAvailable = false;
        
        // Generate subdomain from business name
        function generateSubdomain(businessName) {
            let subdomain = businessName.toLowerCase()
                .replace(/\b(ltd|limited|co|company|inc|llc|plc)\b/gi, '')
                .replace(/[^a-z0-9]/g, '')
                .substring(0, 20);
            return subdomain || '';
        }
        
        // Auto-populate subdomain when business name changes
        businessNameInput.addEventListener('input', function() {
            if (!subdomainManuallyEdited) {
                const suggested = generateSubdomain(this.value);
                subdomainInput.value = suggested;
                if (suggested.length >= 3) {
                    checkSubdomainAvailability(suggested);
                } else {
                    hideFeedback();
                }
            }
        });
        
        // Mark as manually edited when user modifies subdomain
        subdomainInput.addEventListener('input', function() {
            subdomainManuallyEdited = true;
            this.value = this.value.toLowerCase().replace(/[^a-z0-9]/g, '');
        });
        
        // Check availability on blur
        subdomainInput.addEventListener('blur', function() {
            if (this.value.length >= 3) {
                checkSubdomainAvailability(this.value);
            }
        });
        
        // Reset manual edit flag when business name is cleared
        businessNameInput.addEventListener('blur', function() {
            if (!this.value.trim()) {
                subdomainManuallyEdited = false;
            }
        });
        
        function showFeedback(type, message, suggestions = []) {
            subdomainFeedback.style.display = 'block';
            
            let html = '';
            if (type === 'success') {
                html = `<span class="text-success"><i class="bi bi-check-circle me-1"></i>${message}</span>`;
                subdomainInput.classList.remove('is-invalid');
                subdomainInput.classList.add('is-valid');
                subdomainAvailable = true;
            } else if (type === 'error') {
                html = `<span class="text-danger"><i class="bi bi-x-circle me-1"></i>${message}</span>`;
                if (suggestions.length > 0) {
                    const suggestLabel = currentLang === 'sw' ? 'Mapendekezo:' : 'Suggestions:';
                    html += `<div class="mt-1"><small class="text-muted">${suggestLabel} </small>`;
                    suggestions.forEach((s, i) => {
                        html += `<button type="button" class="btn btn-sm btn-outline-primary me-1 mb-1 subdomain-suggestion" data-subdomain="${s}">${s}.fis.co.tz</button>`;
                    });
                    html += '</div>';
                }
                subdomainInput.classList.remove('is-valid');
                subdomainInput.classList.add('is-invalid');
                subdomainAvailable = false;
            } else if (type === 'loading') {
                html = `<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>${message}</span>`;
                subdomainAvailable = false;
            }
            
            subdomainFeedback.innerHTML = html;
            
            // Add click handlers for suggestions
            document.querySelectorAll('.subdomain-suggestion').forEach(btn => {
                btn.addEventListener('click', function() {
                    subdomainInput.value = this.dataset.subdomain;
                    subdomainManuallyEdited = true;
                    checkSubdomainAvailability(this.dataset.subdomain);
                });
            });
        }
        
        function hideFeedback() {
            subdomainFeedback.style.display = 'none';
            subdomainInput.classList.remove('is-valid', 'is-invalid');
            subdomainAvailable = false;
        }
        
        let checkTimeout;
        async function checkSubdomainAvailability(subdomain) {
            clearTimeout(checkTimeout);
            
            const checkingMsg = currentLang === 'sw' ? 'Inakagua...' : 'Checking...';
            showFeedback('loading', checkingMsg);
            
            checkTimeout = setTimeout(async () => {
                try {
                    const response = await fetch(`https://lite.fis.co.tz/api/check-subdomain.php?subdomain=${encodeURIComponent(subdomain)}`);
                    const result = await response.json();
                    
                    if (result.success && result.available) {
                        const availableMsg = currentLang === 'sw' 
                            ? `${subdomain}.fis.co.tz inapatikana!`
                            : `${subdomain}.fis.co.tz is available!`;
                        showFeedback('success', availableMsg);
                    } else {
                        const takenMsg = result.message || (currentLang === 'sw' ? 'Subdomain haipatikani' : 'Subdomain not available');
                        showFeedback('error', takenMsg, result.suggestions || []);
                    }
                } catch (error) {
                    const errorMsg = currentLang === 'sw' ? 'Imeshindwa kukagua' : 'Unable to check';
                    showFeedback('error', errorMsg);
                }
            }, 300);
        }
        
        // Form Submission
        document.getElementById('trialForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Validate subdomain before submit
            const subdomain = subdomainInput.value.trim();
            if (subdomain.length < 3) {
                const errorMsg = currentLang === 'sw' 
                    ? 'Subdomain lazima iwe na herufi 3 au zaidi'
                    : 'Subdomain must be at least 3 characters';
                showFeedback('error', errorMsg);
                subdomainInput.focus();
                return;
            }
            
            if (!subdomainAvailable) {
                const errorMsg = currentLang === 'sw' 
                    ? 'Tafadhali chagua subdomain inayopatikana'
                    : 'Please choose an available subdomain';
                showFeedback('error', errorMsg);
                subdomainInput.focus();
                return;
            }
            
            const submitBtn = document.getElementById('submitBtn');
            const originalText = submitBtn.innerHTML;
            const loadingText = currentLang === 'sw' ? 'Inatuma...' : 'Sending...';
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + loadingText;
            submitBtn.disabled = true;
            
            const formData = new FormData(this);
            formData.append('estimated_price', formTotalPrice.textContent);
            formData.append('language', currentLang);
            
            try {
                const response = await fetch('https://lite.fis.co.tz/api/send-trial-request.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                const alertContainer = document.getElementById('alertContainer');
                
                if (result.success) {
                    const successMsg = currentLang === 'sw' 
                        ? '<strong>Asante!</strong> Ombi lako la majaribio limetumwa. Tutakuwasiliana ndani ya saa 24.'
                        : '<strong>Thank you!</strong> Your trial request has been submitted. We\'ll contact you within 24 hours.';
                    alertContainer.innerHTML = `
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle me-2"></i>
                            ${successMsg}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    `;
                    this.reset();
                    calculateFormPrice();
                    hideFeedback();
                    subdomainManuallyEdited = false;
                    subdomainAvailable = false;
                } else {
                    const errorPrefix = currentLang === 'sw' ? 'Hitilafu:' : 'Error:';
                    alertContainer.innerHTML = `
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-circle me-2"></i>
                            <strong>${errorPrefix}</strong> ${result.error || (currentLang === 'sw' ? 'Imeshindwa kutuma ombi. Tafadhali jaribu tena.' : 'Failed to send request. Please try again.')}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    `;
                }
            } catch (error) {
                const errorMsg = currentLang === 'sw' 
                    ? '<strong>Hitilafu:</strong> Hitilafu ya mtandao. Tafadhali jaribu tena au wasiliana nasi kupitia WhatsApp.'
                    : '<strong>Error:</strong> Network error. Please try again or contact us via WhatsApp.';
                document.getElementById('alertContainer').innerHTML = `
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-circle me-2"></i>
                        ${errorMsg}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                `;
            }
            
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
        
        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                navbar.style.boxShadow = '0 5px 30px rgba(0,0,0,0.15)';
            } else {
                navbar.style.boxShadow = '0 2px 20px rgba(0,0,0,0.1)';
            }
        });
    </script>
</body>
</html>
