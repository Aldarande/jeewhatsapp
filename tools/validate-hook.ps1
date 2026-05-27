# =============================================================================
# JeeWhatsApp — Wrapper Stop hook (variante conservatrice)
#
# Logique :
#   - Docker down               → exit 0 silencieux
#   - Script absent             → exit 0 silencieux
#   - Tout PASS (exit 0)        → exit 0 silencieux
#   - WARN (exit 2)             → affiche, exit 0 (pas de blocage)
#   - FAIL (exit 1)             → affiche + exit 2 (block Claude pour réagir)
#
# Convention Claude Code :
#   exit 0  = continue normalement
#   exit 2  = block stop, force Claude à reprendre la main
# =============================================================================

$ErrorActionPreference = 'Continue'

# 1. Container up ?
$container = docker ps --filter "name=jeedom-dev" --format "{{.Names}}" 2>$null
if (-not $container -or $container -notmatch 'jeedom-dev') {
    exit 0   # Docker pas dispo — skip silencieux
}

# 2. Script de validation présent ?
$scriptPath = '/var/www/html/plugins/jeewhatsapp/tools/validate.sh'
$exists = docker exec jeedom-dev test -f $scriptPath 2>$null
if ($LASTEXITCODE -ne 0) {
    exit 0   # Script manquant — skip silencieux
}

# 3. Exécution
$raw = docker exec jeedom-dev bash $scriptPath 2>&1
$code = $LASTEXITCODE

# 4. Filtre — ne garde que FAIL / WARN / SUMMARY
$relevant = $raw | Select-String -Pattern '^\[(FAIL|WARN)\]|^=== SUMMARY|^PASS=' | ForEach-Object { $_.Line }

switch ($code) {
    0 {
        # Tout passe — silencieux
        exit 0
    }
    2 {
        # WARN seulement - informe mais ne bloque pas
        Write-Output "### Validation JeeWhatsApp - Warnings (non-bloquant)"
        $relevant | ForEach-Object { Write-Output $_ }
        exit 0
    }
    1 {
        # FAIL — block et renvoie à Claude pour réaction
        [Console]::Error.WriteLine("### Validation JeeWhatsApp - FAIL")
        $relevant | ForEach-Object { [Console]::Error.WriteLine($_) }
        [Console]::Error.WriteLine("")
        [Console]::Error.WriteLine("Lance ``tools/validate.sh`` manuellement pour le detail complet.")
        exit 2
    }
    default {
        # Inattendu
        Write-Output "### Validation JeeWhatsApp - code inattendu : $code"
        exit 0
    }
}
