# HAFATRA – Messagerie Instantanée Open Source

## 📱 À propos
HAFATRA est une messagerie instantanée open source inspirée de WhatsApp, construite en PHP/MySQL vanilla.  
Thème : Bleu Twitter (#1DA1F2) et Blanc, avec mode sombre intégré.

---

## 🚀 Installation

### Prérequis
- PHP 7.4+ avec extensions: PDO, PDO_MySQL, GD, fileinfo
- MySQL 5.7+ ou MariaDB 10.3+
- Serveur web : Apache ou Nginx

### Étapes

**1. Copier les fichiers**
```
Copier tout le dossier `hafatra/` dans votre répertoire web (ex: /var/www/html/hafatra)
```

**2. Créer la base de données**
```sql
mysql -u root -p < database.sql
```

**3. Configurer la connexion DB**  
Éditer `config.php` :
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'votre_user');
define('DB_PASS', 'votre_mot_de_passe');
define('DB_NAME', 'hafatra');
define('BASE_URL', 'http://votre-domaine/hafatra/');
```

**4. Permissions d'upload**
```bash
chmod 755 uploads/ uploads/avatars/ uploads/images/ uploads/videos/ uploads/files/
```

**5. Avatar par défaut**  
Placer une image `default.png` dans `uploads/avatars/`

**6. Accéder à l'application**  
Ouvrir : `http://localhost/hafatra/login.php`

---

## ✨ Fonctionnalités

### Messagerie
- ✅ Conversations instantanées (polling 3s)
- ✅ Messages texte avec Entrée pour envoyer
- ✅ Envoi d'images, vidéos, fichiers (max 50MB)
- ✅ Répondre à un message (reply/quote)
- ✅ Modifier un message (texte seulement)
- ✅ Supprimer un message
- ✅ Réactions emoji (❤️ 👍 😂 😮 😢 🙏)
- ✅ Statut de lecture

### Contacts & Conversations
- ✅ Ajout par numéro de téléphone uniquement (pas de recherche par nom)
- ✅ Surnom personnalisé par contact
- ✅ Système de demande de conversation (pending/accepted)
- ✅ Filtre SPAM
- ✅ Blocage / déblocage d'utilisateurs
- ✅ Suppression de conversation

### Profil
- ✅ Photo de profil (upload)
- ✅ Nom et bio modifiables
- ✅ Statut en ligne / hors ligne

### Interface
- ✅ Thème Bleu Twitter & Blanc
- ✅ Mode sombre complet
- ✅ Design responsive (mobile friendly)
- ✅ Notifications toast
- ✅ Visualisation d'images en plein écran

---

## 🗂 Structure des fichiers
```
hafatra/
├── config.php          # Configuration DB et helpers
├── index.php           # Application principale
├── login.php           # Connexion / Inscription
├── logout.php          # Déconnexion
├── api.php             # API backend (toutes les actions)
├── database.sql        # Schéma de la base de données
├── css/
│   └── app.css         # Styles complets
├── js/
│   └── app.js          # JavaScript frontend
└── uploads/
    ├── avatars/        # Photos de profil
    ├── images/         # Images envoyées
    ├── videos/         # Vidéos envoyées
    └── files/          # Autres fichiers
```

---

## 🔒 Sécurité
- Mots de passe hashés avec `password_hash()` (bcrypt)
- Échappement HTML de toutes les entrées
- Vérification de participation pour chaque action
- Sessions PHP sécurisées

---

## 📄 Licence
HAFATRA est open source – libre d'utilisation, modification et distribution.
