<?php
require_once 'config.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php'); exit;
}

$error = '';
$mode = $_GET['mode'] ?? 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if ($mode === 'register') {
        $name = sanitize($_POST['name'] ?? '');
        if (!$phone || !$password || !$name) {
            $error = 'Tous les champs sont obligatoires.';
        } elseif (strlen($password) < 6) {
            $error = 'Mot de passe trop court (min 6 caractères).';
        } else {
            $stmt = db()->prepare("SELECT id FROM users WHERE phone = ?");
            $stmt->execute([$phone]);
            if ($stmt->fetch()) {
                $error = 'Ce numéro est déjà utilisé.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = db()->prepare("INSERT INTO users (phone, name, password_hash) VALUES (?, ?, ?)");
                $stmt->execute([$phone, $name, $hash]);
                $_SESSION['user_id'] = db()->lastInsertId();
                header('Location: index.php'); exit;
            }
        }
    } else {
        $stmt = db()->prepare("SELECT * FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            db()->prepare("UPDATE users SET status='online', last_seen=NOW() WHERE id=?")->execute([$user['id']]);
            header('Location: index.php'); exit;
        } else {
            $error = 'Numéro ou mot de passe incorrect.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>HAFATRA – <?= $mode === 'register' ? 'Créer un compte' : 'Connexion' ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
<style>
:root {
    --twitter-blue: #1DA1F2;
    --twitter-blue-dark: #0f172a;
    --twitter-blue-light: #1e293b;
    --white: #ffffff;
    --gray-100: #f8fafc;
    --gray-200: #e2e8f0;
    --gray-300: #cbd5e1;
    --gray-500: #64748b;
    --gray-700: #1e293b;
    --error: #ef4444;
    --success: #10b981;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow-x: hidden;
}

/* Effet de fond avec nuances de blanc */
body::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: 
        radial-gradient(circle at 20% 80%, rgba(255, 255, 255, 0.05) 0%, transparent 50%),
        radial-gradient(circle at 80% 20%, rgba(29, 161, 242, 0.08) 0%, transparent 50%),
        radial-gradient(circle at 40% 40%, rgba(255, 255, 255, 0.03) 0%, transparent 50%);
    pointer-events: none;
    z-index: 0;
}

.auth-wrapper {
    width: 100%;
    max-width: 1100px;
    padding: 20px;
    position: relative;
    z-index: 1;
}

.auth-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 60px;
    align-items: center;
}

/* Section gauche - Branding (Desktop) */
.brand-section {
    padding: 20px;
    text-align: center;
}

.brand-section .logo {
    margin-bottom: 32px;
}

.logo-icon {
    display: inline-block;
    margin-bottom: 24px;
}

.logo-icon img {
    width: 120px;
    height: 120px;
    object-fit: contain;
    filter: drop-shadow(0 8px 20px rgba(29, 161, 242, 0.3));
    transition: transform 0.3s ease;
}

.logo-icon img:hover {
    transform: scale(1.05);
}

.brand-section h1 {
    font-size: 56px;
    font-weight: 800;
    background: linear-gradient(135deg, var(--twitter-blue), #60a5fa);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    letter-spacing: -1px;
    margin-bottom: 16px;
}

.brand-section .tagline {
    font-size: 28px;
    font-weight: 700;
    color: var(--white);
    margin-bottom: 16px;
    line-height: 1.2;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.brand-section .description {
    color: rgba(255, 255, 255, 0.8);
    font-size: 16px;
    line-height: 1.5;
    max-width: 400px;
    margin: 0 auto;
}

/* Section droite - Formulaire (Desktop) */
.auth-card {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(10px);
    border-radius: 24px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    padding: 48px;
    transition: all 0.3s ease;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.auth-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.3);
}

.auth-header {
    text-align: center;
    margin-bottom: 36px;
}

.auth-header h2 {
    font-size: 32px;
    font-weight: 700;
    color: var(--gray-700);
    margin-bottom: 12px;
}

.auth-header p {
    color: var(--gray-500);
    font-size: 15px;
}

.form-group {
    margin-bottom: 24px;
}

.input-group {
    position: relative;
    display: flex;
    align-items: center;
}

.input-group i {
    position: absolute;
    left: 16px;
    color: var(--gray-500);
    font-size: 16px;
    pointer-events: none;
    transition: all 0.2s;
}

.input-group input {
    width: 100%;
    padding: 14px 16px 14px 48px;
    border: 2px solid var(--gray-200);
    border-radius: 14px;
    font-family: inherit;
    font-size: 15px;
    color: var(--gray-700);
    background: var(--white);
    transition: all 0.2s;
}

.input-group input:focus {
    outline: none;
    border-color: var(--twitter-blue);
    box-shadow: 0 0 0 4px rgba(29, 161, 242, 0.1);
}

.input-group input:focus + i {
    color: var(--twitter-blue);
}

.btn-primary {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, var(--twitter-blue), #0d8fd8);
    color: white;
    border: none;
    border-radius: 14px;
    font-family: inherit;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 8px;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(29, 161, 242, 0.3);
}

.btn-primary:active {
    transform: translateY(0);
}

.error-message {
    background: rgba(239, 68, 68, 0.1);
    border-left: 4px solid var(--error);
    padding: 14px 16px;
    border-radius: 12px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    color: var(--error);
    font-size: 14px;
    backdrop-filter: blur(10px);
}

.error-message i {
    font-size: 18px;
}

.switch-mode {
    text-align: center;
    margin-top: 28px;
    padding-top: 24px;
    border-top: 1px solid var(--gray-200);
}

.switch-mode p {
    color: var(--gray-500);
    font-size: 14px;
}

.switch-mode a {
    color: var(--twitter-blue);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
    margin-left: 6px;
}

.switch-mode a:hover {
    text-decoration: underline;
    color: #0d8fd8;
}

/* Animations Desktop */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

.auth-card {
    animation: fadeInUp 0.6s ease-out;
}

.brand-section {
    animation: fadeIn 0.8s ease-out 0.2s both;
}

/* ============================================ */
/* DESIGN MOBILE - STYLE FACEBOOK LITE */
/* ============================================ */
@media (max-width: 768px) {
    body {
        background: var(--white);
        align-items: flex-start;
    }
    
    body::before {
        display: none;
    }
    
    .auth-wrapper {
        padding: 0;
        max-width: 100%;
    }
    
    .auth-grid {
        grid-template-columns: 1fr;
        gap: 0;
        align-items: flex-start;
    }
    
    /* Branding Mobile - Style Facebook Lite */
    .brand-section {
        background: linear-gradient(135deg, var(--twitter-blue), #0d8fd8);
        padding: 40px 20px 30px;
        text-align: center;
        border-radius: 0 0 24px 24px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }
    
    .logo-icon img {
        width: 70px;
        height: 70px;
        filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.2));
        margin-bottom: 0;
    }
    
    .brand-section h1 {
        font-size: 32px;
        background: white;
        -webkit-background-clip: text;
        -webkit-text-fill-color: white;
        background-clip: text;
        margin-top: 12px;
        margin-bottom: 8px;
    }
    
    .brand-section .tagline {
        font-size: 18px;
        color: rgba(255, 255, 255, 0.95);
        margin-bottom: 8px;
    }
    
    .brand-section .description {
        font-size: 13px;
        color: rgba(255, 255, 255, 0.85);
        max-width: 280px;
        margin: 0 auto;
    }
    
    /* Formulaire Mobile - Style natif */
    .auth-card {
        background: var(--white);
        backdrop-filter: none;
        border-radius: 0;
        box-shadow: none;
        padding: 24px 20px 32px;
        border: none;
        transform: none;
    }
    
    .auth-card:hover {
        transform: none;
        box-shadow: none;
    }
    
    .auth-header {
        text-align: left;
        margin-bottom: 28px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--gray-200);
    }
    
    .auth-header h2 {
        font-size: 24px;
        color: var(--gray-700);
        margin-bottom: 4px;
    }
    
    .auth-header p {
        font-size: 14px;
        color: var(--gray-500);
    }
    
    .form-group {
        margin-bottom: 16px;
    }
    
    .input-group input {
        padding: 16px 16px 16px 48px;
        font-size: 16px;
        border: 1px solid var(--gray-200);
        border-radius: 12px;
        background: var(--gray-100);
    }
    
    .input-group input:focus {
        background: var(--white);
        border-color: var(--twitter-blue);
    }
    
    .btn-primary {
        padding: 16px;
        font-size: 16px;
        border-radius: 12px;
        margin-top: 8px;
        background: var(--twitter-blue);
        box-shadow: 0 2px 8px rgba(29, 161, 242, 0.3);
    }
    
    .btn-primary:active {
        transform: scale(0.98);
    }
    
    .error-message {
        background: rgba(239, 68, 68, 0.08);
        border-left: 3px solid var(--error);
        padding: 12px 16px;
        border-radius: 10px;
        margin-bottom: 20px;
        font-size: 13px;
    }
    
    .switch-mode {
        margin-top: 24px;
        padding-top: 20px;
    }
    
    .switch-mode p {
        font-size: 14px;
    }
    
    .switch-mode a {
        font-size: 14px;
        font-weight: 600;
    }
}

/* Mobile Small (iPhone SE, etc.) */
@media (max-width: 480px) {
    .brand-section {
        padding: 32px 16px 24px;
    }
    
    .logo-icon img {
        width: 60px;
        height: 60px;
    }
    
    .brand-section h1 {
        font-size: 28px;
    }
    
    .brand-section .tagline {
        font-size: 16px;
    }
    
    .brand-section .description {
        font-size: 12px;
    }
    
    .auth-card {
        padding: 20px 16px 28px;
    }
    
    .auth-header h2 {
        font-size: 22px;
    }
    
    .input-group input {
        padding: 14px 14px 14px 44px;
        font-size: 15px;
    }
    
    .btn-primary {
        padding: 14px;
        font-size: 15px;
    }
}

/* Animation pour mobile */
@media (max-width: 768px) {
    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .brand-section {
        animation: slideUp 0.4s ease-out;
    }
    
    .auth-card {
        animation: slideUp 0.4s ease-out 0.1s both;
    }
}

/* Améliorations pour le touch */
@media (max-width: 768px) {
    .btn-primary, 
    .input-group input,
    .switch-mode a {
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
    }
    
    .input-group input {
        -webkit-appearance: none;
        appearance: none;
    }
    
    .btn-primary:active {
        opacity: 0.9;
    }
}
</style>
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-grid">
        <!-- Section gauche - Branding (Desktop et Mobile adapté) -->
        <div class="brand-section">
            <div class="logo">
                <div class="logo-icon">
                    <img src="res/white.png" alt="HAFATRA Logo">
                </div>
                <h1>HAFATRA</h1>
            </div>
            <div class="tagline">
                <?= $mode === 'register' ? 'Rejoignez la conversation' : 'Retrouvez vos amis' ?>
            </div>
            <div class="description">
                <?= $mode === 'register' 
                    ? 'Créez votre compte et commencez à partager des moments importants avec vos proches.'
                    : 'Connectez-vous pour rester en contact avec votre communauté.' 
                ?>
            </div>
        </div>

        <!-- Section droite - Formulaire (Desktop et Mobile adapté) -->
        <div class="auth-card">
            <div class="auth-header">
                <h2><?= $mode === 'register' ? 'Créer un compte' : 'Se connecter' ?></h2>
                <p><?= $mode === 'register' 
                    ? 'C\'est rapide et facile' 
                    : 'Connectez-vous pour continuer' 
                ?></p>
            </div>

            <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST">
                <?php if ($mode === 'register'): ?>
                <div class="form-group">
                    <div class="input-group">
                        <i class="fas fa-user"></i>
                        <input type="text" name="name" placeholder="Nom complet" required autocomplete="name">
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <div class="input-group">
                        <i class="fas fa-phone-alt"></i>
                        <input type="tel" name="phone" placeholder="Numéro de téléphone" required autocomplete="tel">
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="input-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" placeholder="Mot de passe" required autocomplete="<?= $mode === 'register' ? 'new-password' : 'current-password' ?>">
                    </div>
                </div>
                
                <button type="submit" class="btn-primary">
                    <i class="fas <?= $mode === 'register' ? 'fa-user-plus' : 'fa-sign-in-alt' ?>"></i>
                    <?= $mode === 'register' ? 'S\'inscrire' : 'Se connecter' ?>
                </button>
            </form>

            <div class="switch-mode">
                <p>
                    <?php if ($mode === 'register'): ?>
                        Déjà inscrit ?<a href="login.php">Connectez-vous</a>
                    <?php else: ?>
                        Nouveau sur HAFATRA ?<a href="login.php?mode=register">Créez un compte</a>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
</div>
</body>
</html>