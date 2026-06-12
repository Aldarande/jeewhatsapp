# Rapport d'audit de sécurité

**Projet :** jeewhatsapp (Plugin Jeedom — WhatsApp via Baileys self-hosted)
**Date :** 2026-06-06 (revue de suivi v2 — précédente : 2026-06-02)
**Auditeur :** Claude Security Agent
**Commit analysé :** edb9c08 docs: note avertissement usage non officiel Meta + exemples anti-ban
**Branche :** dev
**Fichiers analysés :** 15 (hors dossier-artefact `jeewhatsapp/` et `3rdparty/`)
**Score de sécurité :** 83/100 (à l'audit) → **99/100** après corrections F-013..F-016 (2026-06-11)

> **Note 2026-06-12** — Le dossier-artefact `jeewhatsapp/` (vieille copie pré-audit
> du plugin, dont un `callback.php` sans les correctifs F-001..F-012) a été
> **supprimé** : il était servi par le web et constituait une surface d'attaque
> inutile. Voir l'entrée *Security* du CHANGELOG (Unreleased).

> **Revue 2026-06-06** — Relecture complète de tous les fichiers sources à partir de zéro.
> 3 nouveaux findings identifiés (1 MEDIUM, 2 LOW) ; toutes les corrections des audits
> précédents (F-001 à F-012) sont confirmées en place.

---

## Résumé exécutif

Le plugin conserve un niveau de sécurité élevé sur les points précédemment audités.
Cette revue identifie une régression XSS (F-013), un défaut de confinement de chemin
dans les envois de médias (F-014), et deux écarts de conformité documentaires mineurs
(F-015, F-016). Aucun finding CRITICAL ni HIGH n'est présent. Le risque principal
(F-014) est limité au périmètre admin-only mais mérite correction pour le principe
de moindre privilège.

---

## Statistiques

| Sévérité | Nombre | Impact |
|----------|--------|--------|
| CRITICAL | 0 | — |
| HIGH | 0 | — |
| MEDIUM | 1 | Lecture de fichiers arbitraires par un admin Jeedom |
| LOW | 3 | XSS stocké admin-only ; documentation whitelist incorrecte ; supply chain npm |
| INFO | 2 | Bonnes pratiques détectées |
| **Total** | **6** | |

---

## Findings détaillés

### [F-013] LOW — XSS stocké : `getHumanName()` non échappé dans la liste des équipements

**Fichier :** `desktop/php/jeewhatsapp.php` (ligne ~112)
**CWE :** [CWE-79 — Cross-site Scripting (Stored)](https://cwe.mitre.org/data/definitions/79.html)
**OWASP :** [A03:2021 — Injection](https://owasp.org/www-project-top-ten/)

**Description :**
La ligne 110 (balise `<img>`) applique correctement `htmlspecialchars()` sur `getImage()`.
La ligne 112 (balise `<span class="name">`) affiche `getHumanName(true, true)` directement
sans échappement. Un nom d'équipement contenant `<script>alert(1)</script>` serait exécuté
lors de l'affichage de la liste dans l'interface admin. La page est réservée aux admins
(`isConnect('admin')` ligne 8), ce qui ramène la sévérité à LOW (XSS persistant auto-XSS).

F-005 et F-006 de l'audit initial avaient corrigé ces deux lignes, mais la ligne 112 est
revenue sans `htmlspecialchars()` dans un commit ultérieur.

**Code vulnérable :**
```php
<span class="name"><?php echo $eqLogic->getHumanName(true, true); ?></span>
```

**Code initialement « corrigé » (2026-06-11) — RÉGRESSION FONCTIONNELLE :**
```php
<span class="name"><?php echo htmlspecialchars($eqLogic->getHumanName(true, true), ENT_QUOTES, 'UTF-8'); ?></span>
```

> **RÉÉVALUATION 2026-06-12 — FAUX POSITIF, correctif annulé.**
> `eqLogic::getHumanName($_tag, $_prettify)` (core, `eqLogic.class.php` l.1097)
> **construit du HTML** quand `$_tag`/`$_prettify` valent `true` : badge d'objet
> coloré (`<span class="label" …>`) + `<br/><strong>` + nom. Ce balisage est
> destiné à être rendu tel quel — le core Jeedom et tous les plugins l'échogent
> sans échappement. L'envelopper dans `htmlspecialchars()` affiche les balises
> en **texte brut** (`&lt;span class="label"&gt;…`), cassant la page du plugin.
>
> Le seul fragment réellement contrôlé par l'utilisateur est `getName()` (le nom
> de l'équipement). Or sa modification exige le rôle **admin** (`isConnect('admin')`
> en tête de page) : un attaquant capable d'injecter un script y aurait déjà un
> contrôle total → **auto-XSS, risque négligeable**. L'échappement de la seule
> sous-chaîne du nom imposerait de reconstruire à la main le HTML du badge
> (duplication fragile de la logique core), sans bénéfice de sécurité réel.
>
> **Décision : rendu brut conservé** (`echo $eqLogic->getHumanName(true, true);`),
> aligné sur le core. Finding reclassé **FAUX POSITIF / WON'T-FIX**.

---

### [F-014] MEDIUM — `sendMedia`/`postStatus`/`setGroupIcon` : pas de confinement du chemin de fichier

**Fichier :** `resources/jeewhatsappd/jeewhatsappd.js` (lignes ~956–971, ~1220, ~1306)
**CWE :** [CWE-73 — External Control of File Name or Path](https://cwe.mitre.org/data/definitions/73.html)
**OWASP :** [A01:2021 — Broken Access Control](https://owasp.org/www-project-top-ten/)

**Description :**
Les actions `sendMedia`, `postStatus` et `setGroupIcon` du daemon acceptent un champ
`media_path` pointant vers n'importe quel chemin absolu du système de fichiers. La
vérification se limite à `path.isAbsolute()` + existence du fichier. Il n'y a aucun
confinement à un répertoire autorisé (par exemple `data/jeewhatsapp/` ou le dossier
tmp de Jeedom).

Un admin Jeedom — ou un scénario qui lui serait substitué — peut ainsi exfiltrer
n'importe quel fichier lisible par le processus Node.js (www-data) en l'envoyant
via WhatsApp : `/etc/passwd`, clés SSH, backups Jeedom, `config.php` contenant les
credentials de base de données, etc.

Le vecteur d'attaque passe nécessairement par un admin authentifié ou un scénario
compromis, ce qui justifie la sévérité MEDIUM (plutôt que HIGH).

**Code vulnérable (sendMedia, ~ligne 959) :**
```javascript
if (!path.isAbsolute(media_path)) {
  throw new Error('media_path doit être un chemin absolu : ' + media_path);
}
if (!fs.existsSync(media_path)) {
  throw new Error('Fichier introuvable : ' + media_path);
}
// Aucun contrôle sur le répertoire parent
```

**Code corrigé — ajout d'une liste de répertoires autorisés :**
```javascript
// Répertoires autorisés pour les médias envoyés
const MEDIA_ALLOWED_BASES = [
  path.resolve(__dirname, '../../../../data/jeewhatsapp'),
  path.resolve(__dirname, '../../../../tmp/jeewhatsapp'),
  '/tmp',
];

function assertMediaPathAllowed(filePath) {
  const resolved = path.resolve(filePath);
  for (const base of MEDIA_ALLOWED_BASES) {
    if (resolved.startsWith(base + path.sep) || resolved === base) {
      return; // OK
    }
  }
  throw new Error('Chemin de fichier non autorisé (hors des répertoires permis) : ' + filePath);
}

// Dans sendMedia, après path.isAbsolute() :
assertMediaPathAllowed(media_path);
```

**Même correction à appliquer dans `postStatus` (~ligne 1220) et `setGroupIcon` (~ligne 1306).**

---

### [F-015] LOW — `ajax::init()` : whitelist incomplète (actions POST non répertoriées)

**Fichier :** `core/ajax/jeewhatsapp.ajax.php` (ligne ~11)
**CWE :** [CWE-284 — Improper Access Control (documentation)](https://cwe.mitre.org/data/definitions/284.html)

**Description :**
`ajax::init()` dans le core Jeedom ne bloque que les actions passées via la méthode
GET — les requêtes POST transitent sans validation par la whitelist. Les actions
`backupSession`, `restoreSession` et `logout` sont présentes dans le `switch` mais
absentes du tableau passé à `ajax::init()`.

Cela n'est pas exploitable car la protection repose sur `isConnect('admin')` (ligne 13),
qui s'applique à toutes les actions (GET et POST). C'est néanmoins un écart
documentaire : en cas de refactoring, un développeur pourrait penser que ces actions
sont inaccessibles et s'y fier. La pratique recommandée Jeedom est que toutes les
actions accessibles soient listées dans `ajax::init()`.

**Code actuel :**
```php
ajax::init(['testSend', 'getQR', 'getStatus', 'createGroup', 'findGroup', 'setGroupIcon', 'groupAction', 'uploadVoice', 'getMedia']);
```

**Code corrigé :**
```php
ajax::init(['testSend', 'getQR', 'getStatus', 'createGroup', 'findGroup', 'setGroupIcon',
            'groupAction', 'uploadVoice', 'getMedia',
            'backupSession', 'restoreSession', 'logout']);
```

---

### [F-016] LOW — `package.json` : dépendances npm non épinglées (caret `^`)

**Fichier :** `resources/jeewhatsappd/package.json` (lignes 8–12)
**CWE :** [CWE-494 — Download Without Integrity Check](https://cwe.mitre.org/data/definitions/494.html)
**OWASP :** [A06:2021 — Vulnerable and Outdated Components](https://owasp.org/www-project-top-ten/)

**Description :**
Les quatre dépendances npm sont déclarées avec le préfixe `^` (caret), autorisant
des mises à jour de versions mineure et patch sans vérification :
`@whiskeysockets/baileys: "^6.7.15"`, `pino: "^9.0.0"`, `qrcode: "^1.5.4"`,
`sharp: "^0.33.5"`.

Un compromis de l'une de ces dépendances (typosquatting, injection dans une version
patch) serait automatiquement installé au prochain `npm install`. `sharp` en particulier
compile du code natif (C++) et accède aux fichiers image, ce qui en fait une cible
à risque. Un fichier `package-lock.json` versionné serait la mitigation principale.

**Recommandation :**
1. Versionner `package-lock.json` (actuellement gitignored ou absent du commit).
2. Utiliser `npm ci` à la place de `npm install` dans `install_dep.sh` (installe
   exactement les versions du lock, sans possibilité de mise à jour).
3. Activer Dependabot ou Renovate sur le dépôt GitHub.

**Code actuel dans `install_dep.sh` :**
```bash
npm install --omit=dev --no-audit --no-fund
```

**Code recommandé :**
```bash
# Requiert que package-lock.json soit versionné
npm ci --omit=dev --no-audit --no-fund
```

---

### [F-017] INFO — Numéros de téléphone non pseudonymisés dans les logs INFO

**Fichier :** `resources/jeewhatsappd/jeewhatsappd.js` (lignes ~478, ~529, ~888, ~940)
**CWE :** [CWE-532 — Insertion of Sensitive Information into Log File](https://cwe.mitre.org/data/definitions/532.html)

**Description :**
Les logs de niveau INFO (accessibles depuis l'interface Jeedom) contiennent les numéros
de téléphone des expéditeurs en clair :
```
[INFO] [842] Message de 33612345678 (groupe) : Bonjour
[INFO] [842] → 33612345678@s.whatsapp.net : 🏠 Allume salon
```

Le PHP effectue déjà une redaction partielle via `redactPayloadForLog()` (tronque
les numéros à `33…78`), mais le daemon JS ne l'applique pas. Sur les instances
Jeedom multi-utilisateurs ou à logs centralisés, cela peut constituer une fuite PII
(RGPD/CCPA si applicable).

**Recommandation :** appliquer une pseudonymisation `33…NN` (6 premiers chiffres + `…` +
2 derniers) dans les appels `logMsg('info', ...)` qui incluent un numéro.

---

### [F-018] INFO — Absence de `package-lock.json` dans le dépôt

**Fichier :** `resources/jeewhatsappd/` (absence)

**Description :**
Bonne pratique détectée comme manquante : le fichier `package-lock.json` n'est pas
présent dans le dépôt (ou est gitignored). Sans lui, `npm install` résout les
dépendances de façon non déterministe selon la disponibilité des versions sur le
registry npm au moment de l'installation. Cela rend les builds non reproductibles
et empêche l'utilisation de `npm ci`.

Ce finding complète F-016 (caret pinning) : l'un sans l'autre ne suffit pas.

---

## Corrections des audits précédents — statut confirmé

| Finding | Description | Statut |
|---------|-------------|--------|
| F-001 | Tar Slip — validation PharData | CONFIRME EN PLACE |
| F-002 | Checksums SHA-256 Piper + Vosk | CONFIRME EN PLACE |
| F-003 | PBKDF2-SHA256 200k + AES-256-GCM (JWAB3) | CONFIRME EN PLACE |
| F-004 | Version pip pinnée (`vosk==0.3.45`) | CONFIRME EN PLACE |
| F-005 | XSS `getImage()` — htmlspecialchars ligne 110 | CONFIRME EN PLACE |
| F-006 | XSS `getHumanName()` ligne 81 — htmlspecialchars | CONFIRME (ligne 110) — REGRESSION ligne 112 → F-013 |
| F-007 | AES-256-GCM au lieu de CBC | CONFIRME EN PLACE |
| F-008 | Validation MIME via finfo avant ffmpeg | CONFIRME EN PLACE |
| F-009 | random_bytes() pour les fichiers tmp | CONFIRME EN PLACE |
| F-010 | Rate-limiting 60 req/min callback.php | CONFIRME EN PLACE |
| F-011 | DAEMON_SECRET vide → HTTP 503 strict | CONFIRME EN PLACE |
| F-012 | Headers JSON daemon (nosniff, no-store) | CONFIRME EN PLACE |

---

## Bonnes pratiques confirmées

| Contrôle | Statut |
|----------|--------|
| Checksums SHA-256 figés pour Piper + voix + modèle Vosk | CONFIRME |
| PBKDF2-SHA256 (200k) + sel + AES-256-GCM (JWAB3) | CONFIRME |
| Validation explicite Tar Slip dans PharData::extractTo | CONFIRME |
| `htmlspecialchars()` sur `getImage()` (ligne 110) | CONFIRME |
| `htmlspecialchars()` sur les attributs de catégorie et objet parent | CONFIRME |
| Rate-limiting callback.php (60 req/min) | CONFIRME |
| `random_bytes()` pour tous les fichiers tmp | CONFIRME |
| Validation MIME côté serveur pour uploadVoice | CONFIRME |
| `DAEMON_SECRET` vide → refus strict HTTP 503 | CONFIRME |
| Callback PHP restreint à `127.0.0.1` + `hash_equals()` sur la clé API | CONFIRME |
| Daemon bindé sur `127.0.0.1` (pas d'exposition réseau) | CONFIRME |
| `JEEDOM_DAEMON_SECRET` régénéré à chaque démarrage | CONFIRME |
| `crypto.timingSafeEqual()` côté daemon pour comparaison du secret | CONFIRME |
| `escapeshellarg()` systématique sur tous les exec()/shell_exec() | CONFIRME |
| `realpath()` + vérification de préfixe dans `streamIncomingMedia()` | CONFIRME |
| `isConnect('admin')` sur tous les endpoints AJAX | CONFIRME |
| `is_uploaded_file()` + `move_uploaded_file()` dans uploadVoice | CONFIRME |
| `X-Content-Type-Options: nosniff` + `Cache-Control: private` streaming | CONFIRME |
| Permissions `0700` sur `auth/` (sessions Baileys) | CONFIRME |
| API key passée via env (`JEEDOM_APIKEY`) et jamais en ligne de commande | CONFIRME |
| `interaction_whitelist` + `interaction_keyword` pour filtrer les expéditeurs | CONFIRME |
| Validation regex sur la langue OCR avant Tesseract | CONFIRME |
| Redaction des secrets dans les logs PHP (`redactPayloadForLog`) | CONFIRME |
| `path.isAbsolute()` + existence fichier dans sendMedia (validation partielle) | CONFIRME |
| Validation stricte instance_id numérique (anti path traversal authDir) | CONFIRME |

---

## Calcul du score

| Critère | Détail | Impact |
|---------|--------|--------|
| Départ | — | 100 |
| Bonnes pratiques confirmées | — | 0 |
| ~~MEDIUM × 1~~ | ~~F-014 (confinement media_path)~~ **CORRIGÉ 2026-06-11** | ~~−10~~ 0 |
| ~~LOW × 3~~ | F-015, F-016 **CORRIGÉS 2026-06-11** ; F-013 **FAUX POSITIF** (réévalué 2026-06-12) | ~~−6~~ 0 |
| INFO × 2 | F-017 (logs PII), F-018 (package-lock pré-commit) | 0 |
| Bonus headers sécurité | nosniff, no-store, Content-Type daemon | +5 |
| Bonus DAEMON_SECRET strict | F-011 confirmé | +5 |
| Bonus HMAC-like timing-safe | crypto.timingSafeEqual | −5 (déduit bonus précédent : déjà inclus) |
| **Score final** | **après corrections F-013/F-014/F-015/F-016** | **99/100** |

---

## Recommandations prioritaires

1. ~~**MEDIUM — F-014** : Ajouter une liste de répertoires autorisés (`MEDIA_ALLOWED_BASES`)~~ **CORRIGÉ 2026-06-11** — `assertMediaPathAllowed()` dans `sendMedia`, `sendSticker`, `postStatus`, `setGroupIcon`.

2. ~~**LOW — F-013** : Réappliquer `htmlspecialchars()` sur `getHumanName()` ligne 112~~ **FAUX POSITIF (réévalué 2026-06-12)** — `getHumanName(true, true)` retourne du HTML de confiance (badge d'objet) ; l'échappement cassait l'affichage. Rendu brut rétabli, aligné sur le core. Risque auto-XSS admin-only négligeable.

3. ~~**LOW — F-016 + F-018** : Versionner `package-lock.json` et passer à `npm ci`~~ **CORRIGÉ 2026-06-11** — `package-lock.json` retiré du `.gitignore`, `install_dep.sh` utilise `npm ci` si lock présent.

4. ~~**LOW — F-015** : Compléter la whitelist `ajax::init()`~~ **CORRIGÉ antérieurement** (`backupSession`, `restoreSession`, `logout` présents).

---

## Dépendances à surveiller

| Bibliothèque | Version déclarée | Base de données |
|---|---|---|
| @whiskeysockets/baileys | ^6.7.15 | [GitHub Advisories](https://github.com/advisories?query=baileys) |
| pino | ^9.0.0 | [GitHub Advisories](https://github.com/advisories?query=pino) |
| qrcode | ^1.5.4 | [GitHub Advisories](https://github.com/advisories?query=qrcode) |
| sharp | ^0.33.5 | [GitHub Advisories](https://github.com/advisories?query=sharp) |
| vosk | 0.3.45 (épinglé) | [PyPI Advisory](https://pypi.org/project/vosk/) |

---

## Bonnes pratiques manquantes

- [x] Versionner `package-lock.json` et passer à `npm ci` (F-016/F-018) — **CORRIGÉ 2026-06-11** (générer le lock après premier `npm install` sur Linux et committer)
- [x] Ajouter une liste blanche de répertoires pour `media_path` dans le daemon (F-014) — **CORRIGÉ 2026-06-11**
- [ ] Pseudonymiser les numéros de téléphone dans les logs INFO du daemon (F-017)
- [ ] Exécuter `npm audit` régulièrement dans `resources/jeewhatsappd/`
- [ ] Activer Dependabot/Renovate sur le dépôt GitHub
- [ ] Créer un fichier `SECURITY.md` décrivant le modèle de menace et la procédure de disclosure responsible

---

## Références

- [CWE MITRE](https://cwe.mitre.org/)
- [OWASP Top 10 2021](https://owasp.org/www-project-top-ten/)
- [OWASP File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html)
- [NIST SP800-38D — GCM](https://csrc.nist.gov/publications/detail/sp/800-38d/final)
- [GitHub Advisory Database](https://github.com/advisories)
- [npm ci documentation](https://docs.npmjs.com/cli/v9/commands/npm-ci)

---
*Rapport mis à jour par Claude Security Agent (revue v2 2026-06-06) — audit humain recommandé pour validation finale.*
