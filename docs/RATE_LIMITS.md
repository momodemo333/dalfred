# Dalfred - Limites API des Providers IA (Rate Limits)

## Pourquoi ce document ?

Dalfred utilise les API des providers IA (Claude, Mistral, OpenAI, Gemini) pour fonctionner. Chaque provider impose des **limites d'utilisation** (rate limits) qui contrôlent le nombre de requêtes et de tokens que vous pouvez consommer par minute.

**Comprendre ces limites est essentiel** car une seule interaction Dalfred avec utilisation d'outils (recherche SQL, consultation de données, etc.) génère **2 à 3 appels API** successifs en interne. Si votre tier est trop bas, vous risquez de rencontrer des erreurs `429 Too Many Requests`.

### Pourquoi plusieurs appels API par message ?

Le fonctionnement des outils (tool use) implique un échange en plusieurs étapes :

1. **Appel 1** : Votre message est envoyé au LLM
2. Le LLM décide d'utiliser un outil (ex: requête SQL)
3. Dalfred exécute l'outil localement et récupère le résultat
4. **Appel 2** : Le résultat est renvoyé au LLM qui formule sa réponse
5. Si le LLM a besoin d'un autre outil : **Appel 3**, etc.

C'est le fonctionnement standard de tous les LLM avec tool use. Il n'y a pas d'alternative.

**Estimation de consommation par interaction :**

| Type d'interaction | Appels API | Tokens estimés |
|--------------------|-----------|----------------|
| Message simple (salutation, question générale) | 1 | ~3 000 |
| Requête avec 1 outil (recherche facture, client...) | 2 | ~7 000 |
| Requête complexe (plusieurs outils) | 3-4 | ~12 000+ |

---

## Anthropic (Claude)

### Tiers et conditions d'accès

| Tier | Achat de crédits requis | Limite mensuelle max |
|------|------------------------|---------------------|
| **Tier 1** | 5 $ | 100 $ |
| **Tier 2** | 40 $ | 500 $ |
| **Tier 3** | 200 $ | 1 000 $ |
| **Tier 4** | 400 $ | 5 000 $ |
| Custom | Sur demande | Sur demande |

### Rate limits par modèle et tier

#### Claude Sonnet (4.x)

| Metrique | Tier 1 | Tier 2 | Tier 3 | Tier 4 |
|----------|--------|--------|--------|--------|
| Requêtes/min (RPM) | 50 | 1 000 | 2 000 | 4 000 |
| Tokens entrants/min (ITPM) | 30 000 | 450 000 | 800 000 | 2 000 000 |
| Tokens sortants/min (OTPM) | 8 000 | 90 000 | 160 000 | 400 000 |

#### Claude Haiku (4.5)

| Metrique | Tier 1 | Tier 2 | Tier 3 | Tier 4 |
|----------|--------|--------|--------|--------|
| Requêtes/min (RPM) | 50 | 1 000 | 2 000 | 4 000 |
| Tokens entrants/min (ITPM) | 50 000 | 450 000 | 1 000 000 | 4 000 000 |
| Tokens sortants/min (OTPM) | 10 000 | 90 000 | 200 000 | 800 000 |

#### Claude Opus (4.x)

| Metrique | Tier 1 | Tier 2 | Tier 3 | Tier 4 |
|----------|--------|--------|--------|--------|
| Requêtes/min (RPM) | 50 | 1 000 | 2 000 | 4 000 |
| Tokens entrants/min (ITPM) | 30 000 | 450 000 | 800 000 | 2 000 000 |
| Tokens sortants/min (OTPM) | 8 000 | 90 000 | 160 000 | 400 000 |

### Recommandation pour Dalfred

- **Tier 1 (5 $)** : Suffisant pour tester et pour 1 utilisateur avec usage modéré (50 RPM)
- **Tier 2 (40 $)** : Recommandé pour un usage normal en production (1 000 RPM)
- **Tier 3+ (200 $+)** : Pour plusieurs utilisateurs simultanés

### Comment passer au tier suivant

1. Rendez-vous sur la [Console Anthropic](https://console.anthropic.com/settings/limits)
2. Allez dans **Settings > Plans & Billing**
3. Ajoutez des crédits pour atteindre le seuil requis (le passage est automatique)

**Documentation officielle** : https://platform.claude.com/docs/en/api/rate-limits

---

## Mistral AI

### Tiers et conditions d'accès

| Tier | Condition | Limite mensuelle |
|------|-----------|-----------------|
| **Free (Experiment)** | Aucune | Gratuit |
| **Production** | Ajouter un moyen de paiement | Pay-as-you-go |
| **Enterprise** | Contacter Mistral | Sur demande |

### Rate limits par tier

| Metrique | Free | Production | Enterprise |
|----------|------|------------|------------|
| Requêtes/min (RPM) | **2** | 120 | Custom |
| Tokens/min (TPM) | 500 000 | 1 000 000 | Custom |
| Tokens/mois | 1 000 000 000 | 10 000 000 000 | Custom |

### Attention : le tier Free est inutilisable avec Dalfred

Avec seulement **2 requêtes par minute**, le tier Free ne permet pas de compléter une seule interaction avec outils (qui nécessite 2-3 appels API). **Vous devez impérativement passer au tier Production.**

### Recommandation pour Dalfred

- **Free** : Inutilisable avec les outils (2 RPM insuffisant)
- **Production (paiement requis)** : Indispensable pour tout usage de Dalfred (120 RPM)

### Comment passer au tier Production

1. Rendez-vous sur la [Console Mistral](https://console.mistral.ai)
2. Allez dans **Workspace > Billing**
3. Ajoutez un moyen de paiement (carte bancaire)
4. Le passage au tier Production est automatique
5. Vous pouvez vérifier vos limites sur https://admin.mistral.ai/plateforme/limits

**Documentation officielle** : https://docs.mistral.ai/deployment/ai-studio/tier

---

## OpenAI (GPT)

### Tiers et conditions d'accès

| Tier | Condition | Limite mensuelle max |
|------|-----------|---------------------|
| **Free** | Géographie autorisée | 100 $ |
| **Tier 1** | 5 $ dépensés | 100 $ |
| **Tier 2** | 50 $ dépensés + 7 jours | 500 $ |
| **Tier 3** | 100 $ dépensés + 7 jours | 1 000 $ |
| **Tier 4** | 250 $ dépensés + 14 jours | 5 000 $ |
| **Tier 5** | 1 000 $ dépensés + 30 jours | 200 000 $ |

### Rate limits GPT-4o

| Metrique | Free | Tier 1 | Tier 2 | Tier 3 | Tier 4 | Tier 5 |
|----------|------|--------|--------|--------|--------|--------|
| Requêtes/min (RPM) | 3 | 500 | 5 000 | 5 000 | 10 000 | 10 000 |
| Tokens/min (TPM) | 10 000 | 30 000 | 450 000 | 800 000 | 2 000 000 | 30 000 000 |

### Rate limits GPT-4o-mini

| Metrique | Free | Tier 1 | Tier 2 | Tier 3 | Tier 4 | Tier 5 |
|----------|------|--------|--------|--------|--------|--------|
| Requêtes/min (RPM) | 3 | 500 | 5 000 | 5 000 | 10 000 | 30 000 |
| Tokens/min (TPM) | 60 000 | 200 000 | 2 000 000 | 4 000 000 | 10 000 000 | 150 000 000 |

### Recommandation pour Dalfred

- **Free (3 RPM)** : Inutilisable avec les outils
- **Tier 1 (5 $)** : Suffisant pour tester et 1 utilisateur (500 RPM)
- **Tier 2 (50 $)** : Recommandé pour la production (5 000 RPM)

### Comment passer au tier suivant

1. Rendez-vous sur la [Console OpenAI](https://platform.openai.com/settings/organization/billing)
2. Allez dans **Settings > Billing**
3. Ajoutez des crédits - le tier monte automatiquement en fonction du cumul dépensé et du temps écoulé

**Documentation officielle** : https://developers.openai.com/api/docs/guides/rate-limits/

---

## Google (Gemini)

### Tiers et conditions d'accès

| Tier | Condition |
|------|-----------|
| **Free** | Aucune (clé API gratuite) |
| **Tier 1 (Pay-as-you-go)** | Activer la facturation dans Google Cloud |
| **Tier 2** | Usage accru |
| **Tier 3** | Usage intensif |

### Rate limits Gemini 2.5 Pro

| Metrique | Free | Tier 1 | Tier 2 | Tier 3 |
|----------|------|--------|--------|--------|
| Requêtes/min (RPM) | 5 | 150 | 1 000 | 1 500 |
| Tokens/min (TPM) | 250 000 | 2 000 000 | 4 000 000 | 4 000 000+ |
| Requêtes/jour (RPD) | 100 | 10 000 | Illimité | Illimité |

### Rate limits Gemini 2.5 Flash

| Metrique | Free | Tier 1 | Tier 2 | Tier 3 |
|----------|------|--------|--------|--------|
| Requêtes/min (RPM) | 15 | 1 000 | 2 000 | 4 000 |
| Tokens/min (TPM) | 250 000 | 4 000 000 | 4 000 000 | 4 000 000+ |
| Requêtes/jour (RPD) | 250 | 10 000 | Illimité | Illimité |

### Recommandation pour Dalfred

- **Free (5-15 RPM)** : Utilisable pour tester, mais limité pour la production (100 req/jour pour Pro)
- **Tier 1 (facturation activée)** : Recommandé pour la production (150-1000 RPM)

### Comment passer au tier suivant

1. Rendez-vous sur [Google AI Studio](https://aistudio.google.com)
2. Allez dans les paramètres de votre projet
3. Activez la facturation via Google Cloud Console
4. Le passage aux tiers supérieurs est automatique en fonction de l'usage

**Documentation officielle** : https://ai.google.dev/gemini-api/docs/rate-limits

---

## Tableau comparatif - Tier minimum recommandé pour Dalfred

| Provider | Tier minimum | Cout d'accès | RPM | Suffisant pour |
|----------|-------------|-------------|-----|----------------|
| **Claude (Anthropic)** | Tier 1 | 5 $ | 50 | 1 utilisateur, usage modéré |
| **Mistral** | Production | Carte bancaire | 120 | Plusieurs utilisateurs |
| **OpenAI (GPT)** | Tier 1 | 5 $ | 500 | 1 utilisateur, usage normal |
| **Gemini (Google)** | Free | Gratuit | 5-15 | Tests uniquement |
| **Gemini (Google)** | Tier 1 | Facturation activée | 150-1000 | Production |

### Recommandation globale

Pour une utilisation confortable de Dalfred en production, visez :
- **Minimum 50 RPM** pour 1 utilisateur
- **Minimum 500 RPM** pour 5-10 utilisateurs simultanés
- **1 000+ RPM** pour un usage intensif

---

## Que faire en cas d'erreur 429 (Too Many Requests) ?

1. **Vérifiez votre tier actuel** dans la console de votre provider
2. **Passez au tier supérieur** en suivant les instructions ci-dessus
3. **Espacez vos requêtes** : attendez quelques secondes entre les messages si vous êtes en tier bas
4. **Réduisez le nombre d'utilisateurs simultanés** si nécessaire
5. **Contactez le support du provider** si les limites ne correspondent pas à vos besoins

Dalfred intègre un mécanisme de retry automatique pour les erreurs 429, mais cela ne remplace pas un tier adapté à votre volume d'utilisation.

---

*Document mis à jour le 2 mars 2026. Les tarifs et limites peuvent évoluer. Consultez les documentations officielles pour les informations les plus récentes.*
