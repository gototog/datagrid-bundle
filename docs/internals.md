# Architecture interne — guide du contributeur

Doc pour intervenir **dans** le bundle (ajouter un column type, un thème, une
option de colonne, corriger un template…). Pour l'utilisation du bundle côté
projet, voir [fonctionnement.md](fonctionnement.md).

---

## Carte du dépôt

```
src/
├── KibaticDatagridBundle.php            # Classe bundle (délègue à l'Extension)
├── DependencyInjection/
│   └── KibaticDatagridExtension.php     # Charge services.yaml + prepend AssetMapper
├── Grid/                                # Le cœur, sans dépendance au rendu
│   ├── GridBuilder.php                  # API fluide de construction (service public)
│   ├── Grid.php                         # Objet résultat, consommé par Twig
│   ├── Column.php                       # Une colonne : valeur, template, tri, headerAttr
│   ├── Filter.php                       # Value object : champ de form + callback QB
│   ├── Template.php                     # Constantes des column types fournis
│   └── Theme.php                        # Constantes des thèmes (chemins Twig)
├── Controller/
│   └── DatagridControllerHelper.php     # Trait : CSRF batch, ids batch, form filtres
├── Form/
│   ├── BooleanChoiceType.php            # Oui/Non/Indifférent pour les filtres
│   └── DateRangeType.php                # + Dto/DateRange.php
├── Twig/
│   ├── AppExtension.php                 # Filtres inline_attr, inline_if + datagrid_reset_url
│   └── Components/                      # <twig:datagrid>, <twig:datagrid-filters>
├── Maker/MakeDatagrid.php               # bin/console make:datagrid
└── Resources/
    ├── config/services.yaml             # Déclaration des services
    ├── translations/                    # Domaine KibaticDatagridBundle (en + fr)
    └── views/
        ├── _column_value.html.twig      # Résolution + rendu de la valeur d'une cellule
        ├── column_type/                 # Column types de BASE (fallback ultime)
        ├── components/                  # Templates des composants Twig
        └── theme/
            ├── bootstrap5/              # Thème structurel par défaut
            │   ├── datagrid.html.twig
            │   ├── datagrid-table.html.twig
            │   ├── datagrid-filters.html.twig
            │   └── column_type/         # Surcharges de column types propres au thème
            └── kibatic/                 # Design system Kibatic (mêmes gabarits)

assets/
├── package.json                         # Déclare les contrôleurs Stimulus (section "symfony")
├── dist/                                # Contrôleurs Stimulus (JS publié, pas de build)
└── styles/kibatic/                      # SCSS du thème kibatic (voir son README.md)

templates/maker/GridBuilder.tpl.php      # Squelette généré par make:datagrid
```

## Câblage Symfony

- **`KibaticDatagridExtension::load()`** charge `Resources/config/services.yaml` :
  `GridBuilder` (public), `AppExtension` (tag `twig.extension`), les deux
  composants Twig (tag `twig.component`), `MakeDatagrid` (tag `maker.command`,
  avec `maker.doctrine_helper` et `maker.renderer.form_type_renderer` injectés).
- **`prepend()`** enregistre `assets/dist` dans l'AssetMapper du projet hôte
  sous le namespace `@kibatic/datagrid-bundle` (si AssetMapper ≥ FrameworkBundle
  6.3 est présent). C'est ce qui rend les contrôleurs Stimulus importables sans
  publication d'assets.
- Les contrôleurs Stimulus sont déclarés dans la section `symfony.controllers`
  d'`assets/package.json` ; le projet hôte les active dans son
  `assets/controllers.json`.

## Pipeline de rendu

Qui inclut quoi, du composant à la cellule :

```
<twig:datagrid :grid :form>                      components/datagrid.html.twig
  │  (ajoute .kibatic-datagrid si grid.theme se termine par /kibatic)
  ├─ form défini    → {grid.theme}/datagrid.html.twig
  │                     ├─ {grid.theme}/datagrid-filters.html.twig
  │                     └─ {grid.theme}/datagrid-table.html.twig
  └─ form absent    → {grid.theme}/datagrid-table.html.twig directement

datagrid-table.html.twig
  ├─ <thead> : boucle grid.columns → <th> (tri, headerAttr)   ← PAS d'item ici
  └─ <tbody> : boucle grid.pagination (item)
        └─ <td> par colonne (col_class via templateParameter('col_class', null, item))
              └─ include @KibaticDatagrid/_column_value.html.twig

_column_value.html.twig — résolution du template de la valeur, candidats dans l'ordre :
  1. @KibaticUX/datagrid/column_type/{template}      (surcharge projet via ux-bundle)
  2. {thème}/column_type/{template}                  (pour CHAQUE thème de setTheme(),
                                                      dans l'ordre → fallback)
  3. @KibaticDatagrid/column_type/{template}         (base du bundle)
  4. {template} tel quel                             (chemin custom du projet)
```

`grid.theme` = **premier** thème de la chaîne (structurel : gabarits table +
filtres). `grid.themes` = chaîne complète (résolution des column types).

## Invariants à respecter quand on intervient

Ces règles ne sont pas visibles dans les signatures — les casser produit des
bugs silencieux :

1. **Le `<th>` n'a pas d'entité.** L'en-tête est rendu avant la boucle sur les
   lignes. Tout ce qui touche l'en-tête doit être **statique** → c'est le rôle
   de `Column::$headerAttr`. Ne jamais lire `templateParameters` côté `<th>` :
   ils peuvent être une closure par ligne.
2. **`templateParameters` a deux formes** : tableau statique OU closure globale
   `fn($entity, array $extra) => array`. Toute lecture doit passer par
   `Column::getTemplateParameters($item)` ou, pour un paramètre unitaire avec
   valeur par défaut, `Column::getTemplateParameter($name, $default, $item)` —
   sans `$item`, une closure globale n'est pas résoluble et le défaut est
   renvoyé. Même convention
   de signature que `Column::getValue` (gestion du `$extra` des selects
   additionnels : si `$entity` est un tableau, `[0]` est l'entité, le reste est
   extra).
3. **`getGrid()` est mémoïsé.** Toute méthode de configuration appelée après le
   premier `getGrid()` est sans effet (sauf `getGrid(forceRecreate: true)`).
   Les nouvelles options doivent être transmises au constructeur de `Grid` dans
   `getGrid()`.
4. **Ordre des paramètres = API publique.** `addColumn()` et le constructeur de
   `Column` sont appelés **positionnellement** par les projets (ex.
   `addColumn(name, value, template, params, sortable)`). Tout nouveau paramètre
   s'ajoute **en fin de signature**, jamais au milieu.
5. **Le token CSRF des actions par lot a pour id la classe du GridBuilder**
   (`$this::class` transmis à `Grid` comme `batchActionsTokenId`). Le contrôleur
   destinataire valide avec
   `assertBatchCsrfTokenValid($request, MonGridBuilder::class)`. Renommer un
   GridBuilder projet invalide les tokens en vol — côté bundle, ne pas changer
   ce mécanisme sans migration.
6. **`setTheme()` exige au moins un thème** ; `Grid::getTheme()` lève une
   `LogicException` sur chaîne vide. Le premier thème doit fournir les trois
   gabarits (`datagrid`, `datagrid-table`, `datagrid-filters`).
7. **Le CSS du thème kibatic est scopé sous `.kibatic-datagrid`** (classe posée
   par `components/datagrid.html.twig`). Tout nouveau style du thème doit rester
   sous ce scope pour ne pas fuir dans l'UI du projet hôte (cf.
   `assets/styles/kibatic/README.md` : mixin `components` émise soit scopée —
   `datagrid.scss` — soit globale, assemblée par le projet greenfield).
8. **Toute chaîne visible passe par le domaine de traduction
   `KibaticDatagridBundle`** — ajouter chaque clé dans `en.yaml` ET `fr.yaml`.
9. **`applyFilters()` saute les valeurs vides** mais laisse passer les booléens
   `false` et rejette les `Collection` vides. En tenir compte pour tout nouveau
   type de filtre.
10. **Les `filter` callbacks et `sortableQuery` reçoivent le QueryBuilder
    partagé** : ils font des `andWhere`/`addOrderBy`, jamais de `where`/`orderBy`
    écrasants (sauf `applySort` qui pose le `orderBy` principal).

## Composants internes, un par un

### `GridBuilder` (src/Grid/GridBuilder.php)

Service **stateful** réinitialisé par `initialize()` → `reset()`. Détails non
évidents :

- `initialize()` fait le `handleRequest` du form de filtres seulement s'il
  n'est pas déjà soumis (permet au contrôleur de le soumettre lui-même).
- `applySort()` ne trie que si `?sort_by=` correspond au `sortable` d'une
  colonne **enabled** — pas d'injection possible d'expression arbitraire.
- `buildFilterLayout()` transforme la liste plate de `Filter` en slots pour la
  barre de filtres : un slot par filtre isolé, un slot par groupe (ordre
  d'apparition du premier membre ; le flag `hidden` du premier membre vaut pour
  le groupe).
- Les classes projet **étendent** `GridBuilder` (pattern recommandé) : le
  constructeur (`RequestStack`, `PaginatorInterface`, `ParameterBagInterface`)
  fait partie de l'API — le modifier casse tous les GridBuilder projet.

### `Grid` (src/Grid/Grid.php)

Value object en lecture seule pour Twig. `getRowAttributes($item)` invoque le
`rowAttributesCallback` du builder et sérialise le tableau retourné via
`AppExtension::attributesToHtml` (rendu `{{ grid.rowAttributes(item)|raw }}`
dans les gabarits). C'est **le** pattern de référence pour rendre des attributs
HTML dynamiques.

### `Column` (src/Grid/Column.php)

Trois responsabilités : résoudre la valeur (`getValue`), choisir le template
(`getTemplate` — `TEXT` par défaut, `ARRAY` si la valeur est un tableau),
résoudre les paramètres (`getTemplateParameters`, et `getTemplateParameter`
pour un paramètre unitaire avec défaut). `headerAttr` est un simple
bag statique exposé publiquement, consommé par les gabarits de table
(`headerAttr.class` fusionné dans la classe du `<th>`, le reste rendu via le
filtre `inline_attr`).

### `AppExtension` (src/Twig/AppExtension.php)

- `inline_attr` (filtre, `is_safe html`) : `['class' => 'x', 'data-y' => '1']`
  → ` class="x" data-y="1"`. `true` → attribut nu, `false` → omis, `null` →
  exception. Statique, aussi appelée en PHP (`Grid::getRowAttributes`).
- `inline_if` : chaîne construite des clés dont la valeur n'est pas `false`
  (classes conditionnelles).
- `datagrid_reset_url` : URL courante expurgée des paramètres du form de
  filtres (bouton « réinitialiser »).

### Composants Twig (src/Twig/Components/)

Composants **anonymes** (pas de logique, juste des props typées) :

- `DatagridComponent` (`<twig:datagrid>`) : props `grid`, `form`, `tableSize`,
  `tableStickyScroll`. Template : `components/datagrid.html.twig`.
- `DatagridFiltersComponent` (`<twig:datagrid-filters>`) : props `form`, `grid`,
  `relatedTurboFrames`. Quand `relatedTurboFrames` est non vide, le gabarit
  kibatic passe le form en mode « temps réel » : contrôleur Stimulus
  `form-turbo-frame-updater`, qui recharge les turbo-frames listées à chaque
  changement (debounce 250 ms), sans bouton de soumission.

### Contrôleurs Stimulus (assets/dist/)

JS publié tel quel (pas d'étape de build) — on édite directement les fichiers :

| Contrôleur | Rôle |
|---|---|
| `batch` | Sélection par lot : barre d'actions, compteur, master checkbox, confirmation (SweetAlert2), soumission du form batch |
| `checker` | Version minimale historique du « tout cocher » (thème bootstrap5) |
| `filters-collapse` | Replie/déplie les filtres avancés (toggle `.is-expanded`, le CSS fait le reste) |
| `form-turbo-frame-updater` | Soumet le form de filtres en tâche de fond et recharge des turbo-frames (dépend de lodash `debounce`) |

Le form batch du thème kibatic est **hors** de la table (attribut HTML `form=`
sur les checkboxes) car des cellules contiennent leurs propres formulaires —
l'imbrication de `<form>` est interdite en HTML. Conserver ce découplage.

### SCSS du thème kibatic (assets/styles/kibatic/)

Architecture documentée dans son propre `README.md`. L'essentiel :

- `_styles.scss` expose la mixin `components` (tous les composants du design
  system) ; `datagrid.scss` l'émet **scopée** sous `.kibatic-datagrid`
  (intégration sans effet de bord), un projet greenfield peut l'émettre
  globalement en composant les partials.
- Recoloration par projet via `--brand-500` (adopte `--bs-primary` de l'hôte
  si présent).
- Le projet hôte doit ajouter `assets/styles` du bundle au `load_path` du
  sass-bundle.

### Maker (src/Maker/MakeDatagrid.php)

`make:datagrid` : interroge l'entité (DoctrineHelper), génère une classe
`App\Datagrid\<Entity>GridBuilder` depuis `templates/maker/GridBuilder.tpl.php`
avec une colonne par champ mappé + colonne Actions, et propose de générer le
FormType de filtres. Quand l'API de `GridBuilder` change (nouveau paramètre,
nouvelle convention), **mettre à jour le template du maker** en conséquence.

## Développer et tester

Le bundle n'a pas de suite de tests propre. Le banc d'essai est le projet
`demo-kibatic`, qui le charge en **path repository** Composer
(`packages/datagrid-bundle`) : toute modification du bundle est prise en compte
immédiatement (symlink), sans publication.

- Tests fonctionnels : `make tests` dans demo-kibatic — les `testIndex` de
  chaque contrôleur rendent une grille complète (thème kibatic), donc toute
  erreur Twig/PHP du bundle les fait échouer.
- Vérification visuelle : `docker compose up` puis les pages `/product`,
  `/client`, `/project`, `/user` (grilles réelles avec filtres, groupes,
  badges, batch actions).
- Après modification des SCSS : `make assets` (ou `assets-dev` en watch).
- Penser à vider le cache Symfony de la démo si un changement de config/DI du
  bundle ne semble pas pris en compte.

## Checklists d'intervention

**Ajouter un column type**
1. Créer `src/Resources/views/column_type/<nom>.html.twig` (variables reçues :
   `column`, `parameters`, `item`, `value`).
2. Variante par thème si le rendu diffère :
   `theme/kibatic/column_type/<nom>.html.twig`, etc.
3. Ajouter la constante dans `Template.php`.
4. Traductions éventuelles dans les deux `KibaticDatagridBundle.*.yaml`.
5. Documenter les `parameters` supportés en tête du template (convention des
   templates kibatic existants).

**Ajouter une option de colonne**
1. Propriété + paramètre **en fin** de constructeur de `Column`.
2. Paramètre en fin de `GridBuilder::addColumn`, transmis au `new Column`.
3. Consommation dans les gabarits des **deux** thèmes.
4. Se demander : l'option est-elle lue côté `<th>` (→ statique obligatoire) ou
   côté `<td>` (→ closure par ligne possible) ? Cf. invariants 1 et 2.
5. Mettre à jour le template du maker si l'option a vocation à être générée.

**Ajouter un thème**
1. Dossier `src/Resources/views/theme/<nom>/` avec les trois gabarits
   (`datagrid`, `datagrid-table`, `datagrid-filters`) — s'inspirer de kibatic,
   le plus complet.
2. Constante dans `Theme.php`.
3. Les column types manquants peuvent être omis : déclarer le thème en chaîne
   avec un fallback (`setTheme(Theme::MONTHEME, Theme::BOOTSTRAP5)`).

**Ajouter un contrôleur Stimulus**
1. Fichier dans `assets/dist/` (JS direct, pas de build).
2. Entrée dans la section `symfony.controllers` d'`assets/package.json`.
3. L'activer dans l'`assets/controllers.json` du projet hôte (et documenter
   cette étape dans le README).
