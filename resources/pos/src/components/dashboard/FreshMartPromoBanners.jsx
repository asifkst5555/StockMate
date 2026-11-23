import React from "react";
import { Link } from "react-router-dom";

const CategoryIcon = ({ type }) => {
    const commonProps = {
        className: "fm-promo-tile-icon",
        viewBox: "0 0 24 24",
        fill: "none",
        stroke: "currentColor",
        strokeWidth: "2",
        strokeLinecap: "round",
        strokeLinejoin: "round",
        "aria-hidden": "true",
    };

    switch (type) {
        case "leaf":
            return (
                <svg {...commonProps}>
                    <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z" />
                    <path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12" />
                </svg>
            );
        case "shopping-basket":
            return (
                <svg {...commonProps}>
                    <path d="m15 11-1 9" />
                    <path d="m19 11-4-7" />
                    <path d="M2 11h20" />
                    <path d="m3.5 11 1.6 7.4a2 2 0 0 0 2 1.6h9.8a2 2 0 0 0 2-1.6l1.7-7.4" />
                    <path d="M4.5 15.5h15" />
                    <path d="m5 11 4-7" />
                    <path d="m9 11 1 9" />
                </svg>
            );
        case "cup-soda":
            return (
                <svg {...commonProps}>
                    <path d="m6 8 1.75 12.28a2 2 0 0 0 2 1.72h4.54a2 2 0 0 0 2-1.72L18 8" />
                    <path d="M5 8h14" />
                    <path d="M7 15a6.47 6.47 0 0 1 5 0 6.47 6.47 0 0 0 5 0" />
                    <path d="m12 8 1-6h2" />
                </svg>
            );
        case "house":
        default:
            return (
                <svg {...commonProps}>
                    <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" />
                    <path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                </svg>
            );
    }
};

const PROMO_TILES = [
    {
        title: "Fresh Produce & Organic",
        subtitle: "Bananas, Honeycrisp apples, avocados & greens",
        image: "/uploads/northstar/banners/banner-fresh-produce.webp",
        icon: "leaf",
        to: "/app/user/products",
    },
    {
        title: "Pantry & Breakfast Favorites",
        subtitle: "Cheerios, Kellogg's, Heinz, Kraft & Barilla",
        image: "/uploads/northstar/banners/banner-pantry-breakfast.webp",
        icon: "shopping-basket",
        to: "/app/user/products",
    },
    {
        title: "Beverages & Snacks",
        subtitle: "Coca-Cola, Pepsi, Lay's, Doritos & Oreo",
        image: "/uploads/northstar/banners/banner-beverages-snacks.webp",
        icon: "cup-soda",
        to: "/app/user/products",
    },
    {
        title: "Household & Personal Care",
        subtitle: "Tide, Dawn, Bounty, Charmin, Dove & Colgate",
        image: "/uploads/northstar/banners/banner-household-care.webp",
        icon: "house",
        to: "/app/user/product-categories",
    },
];

const FreshMartPromoBanners = () => {
    return (
        <section className="fm-promo-section" aria-label="NorthStar Market Highlights">
            <div className="fm-hero-banner">
                <img
                    src="/uploads/northstar/banners/banner-northstar-hero.webp"
                    alt="NorthStar Market – Neighborhood Supermarket Essentials"
                    className="fm-hero-banner-bg"
                    loading="lazy"
                />
                <div className="fm-hero-banner-overlay">
                    <div>
                        <span className="fm-hero-eyebrow">NorthStar Market • NYC</span>
                        <h3 className="fm-hero-title">
                            NorthStar Market – Everyday American Essentials
                        </h3>
                        <p className="fm-hero-sub">
                            Multi-branch US supermarket inventory across Manhattan, Brooklyn &amp; Queens with real-time USD pricing and POS checkout.
                        </p>
                    </div>
                    <div className="d-flex flex-wrap gap-2">
                        <Link to="/app/user/products" className="btn btn-primary btn-sm px-3">
                            Browse Catalog
                        </Link>
                        <Link to="/app/user/pos" className="btn btn-light btn-sm px-3 fw-semibold">
                            Open POS
                        </Link>
                    </div>
                </div>
            </div>

            <div className="fm-promo-grid">
                {PROMO_TILES.map((tile) => (
                    <Link key={tile.title} to={tile.to} className="fm-promo-tile">
                        <img
                            src={tile.image}
                            alt={tile.title}
                            loading="lazy"
                        />
                        <div className="fm-promo-tile-scrim">
                            <div className="fm-promo-tile-heading">
                                <CategoryIcon type={tile.icon} />
                                <p className="fm-promo-tile-title">{tile.title}</p>
                            </div>
                            <p className="fm-promo-tile-sub">{tile.subtitle}</p>
                        </div>
                    </Link>
                ))}
            </div>
        </section>
    );
};

export default FreshMartPromoBanners;
