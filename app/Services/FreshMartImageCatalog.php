<?php

namespace App\Services;

class FreshMartImageCatalog
{
    public const FALLBACK_PATH = '/uploads/freshmart/products/fallback-grocery.webp';
    public const FALLBACK_THUMB = '/uploads/freshmart/products/thumbs/fallback-grocery.webp';

    /** @var array<string, array{main:string,thumb:string,slug:string}>|null */
    private static ?array $northStarProductMap = null;

    /** @var array<string, string>|null */
    private static ?array $northStarCategoryMap = null;

    private static function bootNorthStarMaps(): void
    {
        if (self::$northStarProductMap !== null) {
            return;
        }
        self::$northStarProductMap = [];
        self::$northStarCategoryMap = [];

        $jsonPath = database_path('seeders/data/northstar_usa_catalog.json');
        if (file_exists($jsonPath)) {
            $data = json_decode(file_get_contents($jsonPath), true);
            foreach (($data['products'] ?? []) as $p) {
                $key = strtolower(trim((string) ($p['name'] ?? '')));
                $slug = (string) ($p['slug'] ?? '');
                $main = (string) ($p['local_path'] ?? '');
                $thumb = (string) ($p['thumb_path'] ?? $main);
                if ($key !== '' && $main !== '' && file_exists(public_path($main))) {
                    self::$northStarProductMap[$key] = [
                        'main' => $main,
                        'thumb' => file_exists(public_path($thumb)) ? $thumb : $main,
                        'slug' => $slug,
                    ];
                }
            }
            $catNameToSlug = [
                'fresh produce' => 'cat-fresh-produce',
                'meat & seafood' => 'cat-meat-seafood',
                'dairy & eggs' => 'cat-dairy-eggs',
                'bakery' => 'cat-bakery',
                'beverages' => 'cat-beverages',
                'snacks & candy' => 'cat-snacks-candy',
                'breakfast & cereal' => 'cat-breakfast-cereal',
                'pantry & cooking' => 'cat-pantry-cooking',
                'frozen foods' => 'cat-frozen-foods',
                'household essentials' => 'cat-household-essentials',
                'personal care' => 'cat-personal-care',
                'baby products' => 'cat-baby-products',
                'pet supplies' => 'cat-pet-supplies',
                'health & wellness' => 'cat-health-wellness',
            ];
            foreach ($catNameToSlug as $cname => $slug) {
                $path = "/uploads/northstar/categories/{$slug}.webp";
                if (file_exists(public_path($path))) {
                    self::$northStarCategoryMap[$cname] = $path;
                }
            }
        }
    }

    /**
     * Resolve a local WebP image & thumbnail path for a grocery product by name/category.
     * Supports NorthStar Market (USA) authentic product packshots first, then FreshMart fallback.
     *
     * @return array{main: string, thumb: string, slug: string}|null
     */
    public static function resolveProductImage(?string $name, ?string $categoryName = null): ?array
    {
        $n = strtolower(trim((string) $name));
        if ($n === '') {
            return null;
        }

        // 1. Exact NorthStar Market USA authentic product match
        self::bootNorthStarMaps();
        if (isset(self::$northStarProductMap[$n])) {
            return self::$northStarProductMap[$n];
        }

        // 2. Legacy FreshMart Bangladesh demo match (preserves owner@freshmart.demo stores 474/475/476)
        $rules = [
            'puffed-flattened-rice' => ['puffed rice', 'muri', 'flattened rice', 'chira'],
            'rice-aromatic-basmati' => ['basmati', 'chinigura', 'aromatic rice'],
            'rice-bran-sunflower-oil' => ['rice bran', 'sunflower oil'],
            'mustard-oil-bottle' => ['mustard oil'],
            'soybean-oil-bottle' => ['soybean oil', 'cooking oil'],
            'desi-ghee-jar' => ['ghee'],
            'baby-diapers-wipes' => ['diaper', 'wet wipes'],
            'infant-formula-cereal' => ['infant cereal', 'infant formula'],
            'baby-lotion-bottle' => ['baby', 'feeding bottle'],
            'farm-fresh-eggs' => ['egg'],
            'milk-powder-pack' => ['milk powder', 'condensed milk'],
            'butter-yogurt-cheese' => ['butter', 'yogurt', 'doi', 'cheese'],
            'liquid-milk-pack' => ['liquid milk', 'uht', 'labang', 'flavored milk', 'milk'],
            'vanilla-ice-cream-tub' => ['ice cream'],
            'frozen-paratha-pack' => ['paratha', 'dal puri', 'samosa', 'spring roll'],
            'frozen-chicken-nuggets' => ['nugget', 'sausage', 'meatball', 'chicken strip', 'burger patty', 'french fries', 'green peas'],
            'turmeric-powder-pack' => ['turmeric'],
            'red-chili-powder-pack' => ['chili powder', 'chilli'],
            'biryani-curry-masala-box' => ['masala', 'biryani'],
            'iodized-salt-pack' => ['salt'],
            'coriander-cumin-spices' => ['coriander', 'cumin', 'jira', 'pepper', 'cardamom', 'elachi', 'cinnamon'],
            'black-tea-pack' => ['tea'],
            'instant-coffee-jar' => ['coffee'],
            'mango-orange-juice' => ['mango fruit', 'mango juice', 'orange fruit', 'litchi'],
            'malt-health-drink-jar' => ['malt', 'health drink'],
            'cola-water-bottles' => ['cola', 'lemon soda', 'water'],
            'ketchup-pickle-jar' => ['ketchup', 'pickle', 'achar'],
            'noodles-pasta-pack' => ['noodle', 'pasta'],
            'chanachur-peanuts-pack' => ['chanachur', 'peanut', 'potato crackers'],
            'biscuits-toast-pack' => ['biscuit', 'toast', 'wafer', 'cake'],
            'toothpaste-toothbrush' => ['toothpaste', 'toothbrush'],
            'shampoo-hair-oil' => ['shampoo', 'hair oil'],
            'beauty-soap-bars' => ['soap'],
            'lotion-facewash-tissue' => ['lotion', 'face wash', 'handwash', 'petroleum jelly', 'shaving', 'talcum', 'facial tissue'],
            'detergent-powder-liquid' => ['detergent', 'fabric softener'],
            'dishwash-liquid-bar' => ['dishwash', 'scrubber'],
            'toilet-floor-cleaner' => ['toilet cleaner', 'floor disinfectant', 'glass & surface', 'air freshener'],
            'mosquito-spray-tissue' => ['mosquito', 'kitchen towel', 'toilet tissue', 'garbage'],
            'wheat-atta-maida' => ['atta', 'maida', 'semolina', 'suji', 'flour', 'besan'],
            'red-lentils-dal' => ['lentil', 'moshur', 'mung dal', 'dal', 'chickpea', 'chola'],
            'white-sugar-pack' => ['sugar'],
            'rice-miniket-pack' => ['miniket', 'nazirshail', 'br-28', 'rice'],
        ];

        foreach ($rules as $slug => $kws) {
            foreach ($kws as $kw) {
                if (str_contains($n, $kw)) {
                    $main = "/uploads/freshmart/products/{$slug}.webp";
                    $thumb = "/uploads/freshmart/products/thumbs/{$slug}.webp";
                    if (file_exists(public_path($main))) {
                        return [
                            'main' => $main,
                            'thumb' => file_exists(public_path($thumb)) ? $thumb : $main,
                            'slug' => $slug,
                        ];
                    }
                }
            }
        }

        if ($categoryName) {
            $catImg = self::resolveCategoryImage($categoryName);
            if ($catImg) {
                return ['main' => $catImg, 'thumb' => $catImg, 'slug' => 'category-fallback'];
            }
        }

        if (file_exists(public_path(self::FALLBACK_PATH))) {
            return [
                'main' => self::FALLBACK_PATH,
                'thumb' => file_exists(public_path(self::FALLBACK_THUMB)) ? self::FALLBACK_THUMB : self::FALLBACK_PATH,
                'slug' => 'fallback-grocery',
            ];
        }

        return null;
    }

    public static function resolveCategoryImage(?string $categoryName): ?string
    {
        $c = strtolower(trim((string) $categoryName));
        if ($c === '') {
            return null;
        }

        // 1. Exact NorthStar Market USA category match
        self::bootNorthStarMaps();
        if (isset(self::$northStarCategoryMap[$c])) {
            return self::$northStarCategoryMap[$c];
        }

        // 2. Legacy FreshMart category match
        $map = [
            'rice' => 'cat-rice-grains',
            'grain' => 'cat-rice-grains',
            'oil' => 'cat-cooking-oil',
            'spice' => 'cat-spices',
            'beverage' => 'cat-beverages',
            'snack' => 'cat-snacks',
            'dairy' => 'cat-dairy',
            'frozen' => 'cat-frozen-food',
            'personal' => 'cat-personal-care',
            'household' => 'cat-household-cleaning',
            'baby' => 'cat-baby-products',
        ];

        foreach ($map as $kw => $slug) {
            if (str_contains($c, $kw)) {
                $path = "/uploads/freshmart/categories/{$slug}.webp";
                if (file_exists(public_path($path))) {
                    return $path;
                }
            }
        }

        return null;
    }
}
