import React from "react";
import { Link } from "react-router-dom";

const PROMO_TILES = [
    {
        title: "Fresh Produce & Organic",
        subtitle: "Bananas, Honeycrisp apples, avocados & greens",
        image: "/uploads/northstar/banners/banner-fresh-produce.webp",
        to: "/app/user/products",
    },
    {
        title: "Pantry & Breakfast Favorites",
        subtitle: "Cheerios, Kellogg's, Heinz, Kraft & Barilla",
        image: "/uploads/northstar/banners/banner-pantry-breakfast.webp",
        to: "/app/user/products",
    },
    {
        title: "Beverages & Snacks",
        subtitle: "Coca-Cola, Pepsi, Lay's, Doritos & Oreo",
        image: "/uploads/northstar/banners/banner-beverages-snacks.webp",
        to: "/app/user/products",
    },
    {
        title: "Household & Personal Care",
        subtitle: "Tide, Dawn, Bounty, Charmin, Dove & Colgate",
        image: "/uploads/northstar/banners/banner-household-care.webp",
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
                            <p className="fm-promo-tile-title">{tile.title}</p>
                            <p className="fm-promo-tile-sub">{tile.subtitle}</p>
                        </div>
                    </Link>
                ))}
            </div>
        </section>
    );
};

export default FreshMartPromoBanners;
