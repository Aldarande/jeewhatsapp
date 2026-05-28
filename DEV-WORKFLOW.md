# Workflow de développement — JeeWhatsApp

Process à appliquer **pour chaque feature** du `ROADMAP.md`.

---

## Pipeline standard (par feature)

```
┌─────────────────────────────────────────────────────────────────────┐
│ 1. SPEC                                                              │
│    └─ Identifier l'item dans ROADMAP.md                              │
│    └─ Créer task via TaskCreate                                      │
│                                                                       │
│ 2. EXPERT JEEDOM (dev)                                               │
│    └─ Invoke Skill `jeedom-plugin-expert` avec contexte feature      │
│    └─ Appliquer les recommandations                                  │
│                                                                       │
│ 3. VALIDATION AUTO                                                   │
│    └─ docker exec jeedom-dev bash tools/validate.sh                  │
│    └─ Exit 0 ou 2 → OK / Exit 1 → corriger et reboucler              │
│                                                                       │
│ 4. INTÉGRATION SCÉNARIO (si nouvelle cmd action/info)                │
│    └─ Vérifier subType conforme aux scénarios Jeedom                 │
│    └─ Tester déclenchement depuis un scénario factice                │
│    └─ Ajouter exemple "recette scénario" dans docs/                  │
│                                                                       │
│ 5. EXPERT DESIGN (widget + UI desktop)                               │
│    └─ Agent général : revue UX, ergonomie, accessibilité             │
│    └─ Adapter core/template/dashboard/jeewhatsapp.html (si widget)   │
│    └─ Adapter desktop/php/jeewhatsapp.php (si onglet/champ)          │
│                                                                       │
│ 6. DOCUMENTATION                                                     │
│    └─ docs/fr_FR/index.md : section dédiée + capture si UI           │
│    └─ CLAUDE.md : MAJ si architecture impactée                       │
│    └─ ROADMAP.md : cocher l'item                                     │
│                                                                       │
│ 7. CHANGELOG                                                         │
│    └─ Ajouter entrée Keep-a-Changelog dans CHANGELOG.md              │
│                                                                       │
│ 8. VALIDATION FINALE                                                 │
│    └─ tools/validate.sh à nouveau                                    │
│                                                                       │
│ 9. GIT                                                               │
│    └─ git add + git commit (template ci-dessous)                     │
│    └─ git push origin dev                                            │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Template commit

```
feat(<scope>): <résumé court>

ROADMAP : v0.X item #N — <titre>

Implémentation :
- <points clés côté daemon JS>
- <points clés côté PHP>
- <points clés UI>

Scénario Jeedom :
- <type cmd>, <comportement>, <exemple usage>

Widget / Desktop :
- <adaptations UI>

Docs : docs/fr_FR/index.md (section <X>)
Changelog : v0.X — section Added/Changed
Validation : PASS=N WARN=M FAIL=0
```

---

## Quels "experts" appeler ?

| Étape | Outil | Pourquoi |
|---|---|---|
| Spec/dev | Skill `jeedom-plugin-expert` | Patterns Jeedom 4.4 (eqLogic, cmd, daemon, AJAX) |
| Validation | `tools/validate.sh` + hook Stop | Détecte régressions immédiatement |
| Design widget | Agent général (sub-agent) | Pas d'expert UI dédié — utiliser un agent général avec brief |
| Sécurité (occasionnelle) | Skill `security-audit` | Tous les 3-4 features ou avant release |

---

## Règles d'or

1. **Une feature = une branche** ? Optionnel pour le moment, tout sur `dev`. Si la feature dure > 1 jour, branche `feat/<id>-<slug>` recommandée puis squash-merge dans dev.
2. **Pas de feature livrée si `validate.sh` est en FAIL**. WARN tolérés (ex: Bad MAC).
3. **Toujours mettre à jour CHANGELOG.md** dans le même commit que la feature, jamais en commit séparé "doc:".
4. **Push après chaque feature complète**, jamais en milieu de feature.
5. **Si une feature révèle un bug existant** → créer une issue (GitHub) et ne pas l'intégrer au commit feature.

---

## Format CHANGELOG.md

Conforme à [Keep a Changelog 1.1.0](https://keepachangelog.com/fr/1.1.0/) et [Semantic Versioning 2.0.0](https://semver.org/).

```markdown
## [Unreleased]
### Added
- Nouvelle commande `send_X` ...
### Changed
- ...
### Fixed
- ...
### Security
- ...

## [0.1.0] - 2026-05-15
### Added
- Initial release : ...
```

À chaque release, déplacer le contenu de `[Unreleased]` vers une nouvelle section versionnée.
