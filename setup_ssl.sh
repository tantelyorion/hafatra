#!/bin/bash
# ============================================================
# HAFATRA - Script SSL Let's Encrypt
# Exécuter sur le serveur en tant que root ou sudo
# ============================================================

DOMAIN="votre-domaine.com"   # ← Remplacez par votre vrai domaine
WEBROOT="/var/www/hafatra"   # ← Remplacez par votre dossier

echo "=== Installation Certbot ==="
# Ubuntu/Debian
apt-get update
apt-get install -y certbot

# Si Apache :
apt-get install -y python3-certbot-apache
certbot --apache -d $DOMAIN -d www.$DOMAIN

# Si Nginx :
# apt-get install -y python3-certbot-nginx
# certbot --nginx -d $DOMAIN -d www.$DOMAIN

echo ""
echo "=== Test du renouvellement automatique ==="
certbot renew --dry-run

echo ""
echo "=== Vérification SSL ==="
echo "Testez sur : https://www.ssllabs.com/ssltest/analyze.html?d=$DOMAIN"

echo ""
echo "=== IMPORTANT : Après SSL, mettez à jour config.php ==="
echo "Changez BASE_URL en : https://$DOMAIN/"
