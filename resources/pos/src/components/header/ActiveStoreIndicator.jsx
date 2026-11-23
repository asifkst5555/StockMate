import React, { useMemo, useState } from "react";
import { Dropdown } from "react-bootstrap";
import { useDispatch, useSelector } from "react-redux";
import { useNavigate } from "react-router-dom";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import {
    faCheck,
    faChevronDown,
    faStore,
} from "@fortawesome/free-solid-svg-icons";
import { ROLES } from "../../constants";
import { changeStore } from "../../store/action/storeAction";
import "./ActiveStoreIndicator.css";

/**
 * Dynamically splits a store display name such as "NorthStar Market – Manhattan"
 * into primary store name and branch label without hardcoding any store names.
 */
const parseStoreName = (rawName) => {
    const clean = (rawName || "").trim();
    if (!clean) {
        return { storeTitle: "", branchLabel: "" };
    }

    const separators = [" – ", " — ", " - "];
    for (const sep of separators) {
        if (clean.includes(sep)) {
            const [left, ...rest] = clean.split(sep);
            const right = rest.join(sep).trim();
            if (left.trim() && right) {
                const branchLabel = /branch/i.test(right)
                    ? right
                    : `${right} Branch`;
                return {
                    storeTitle: left.trim(),
                    branchLabel,
                };
            }
        }
    }

    return { storeTitle: clean, branchLabel: "" };
};

const buildLocationString = (frontSettingValue) => {
    if (!frontSettingValue) {
        return "";
    }
    const city = (frontSettingValue.city || "").trim();
    const state = (frontSettingValue.state || "").trim();
    const country = (frontSettingValue.country || "").trim();
    const parts = [city, state, country].filter(Boolean);
    // Avoid duplicating adjacent identical segments
    const deduped = parts.filter(
        (part, idx) =>
            idx === 0 || part.toLowerCase() !== parts[idx - 1].toLowerCase()
    );
    return deduped.join(", ");
};

const ActiveStoreIndicator = () => {
    const dispatch = useDispatch();
    const navigate = useNavigate();
    const { loginUser, stores, frontSetting } = useSelector((state) => state);
    const [selectedStoreOverride, setSelectedStoreOverride] = useState(null);
    const [switchingId, setSwitchingId] = useState(null);

    const isSuperAdmin = loginUser?.roles === ROLES.SUPER_ADMIN;

    const authorizedStores = useMemo(() => {
        if (!Array.isArray(stores)) {
            return [];
        }
        return stores.filter(
            (store) =>
                store?.attributes?.status === undefined ||
                store?.attributes?.status === null ||
                Boolean(store?.attributes?.status)
        );
    }, [stores]);

    const activeStore = useMemo(() => {
        if (authorizedStores.length === 0) {
            return null;
        }
        return (
            authorizedStores.find((store) => store?.attributes?.active) ||
            authorizedStores[0]
        );
    }, [authorizedStores]);

    if (isSuperAdmin || !activeStore) {
        return null;
    }

    const displayName =
        activeStore?.attributes?.name || selectedStoreOverride?.name || "";
    const { storeTitle, branchLabel } = parseStoreName(displayName);
    const locationText = buildLocationString(frontSetting?.value);

    const subtitleParts = [branchLabel, locationText].filter(Boolean);
    const subtitleText =
        subtitleParts.length > 0 ? subtitleParts.join(" · ") : "Active Store";

    const fullTooltip = subtitleParts.length
        ? `${storeTitle} — ${subtitleParts.join(" · ")}`
        : displayName;

    const handleSwitchStore = async (store) => {
        if (!store || store.id === activeStore.id || switchingId !== null) {
            return;
        }
        setSwitchingId(store.id);
        try {
            await dispatch(
                changeStore(
                    store.id,
                    {
                        tenant_id: store?.attributes?.tenant_id,
                        name: store?.attributes?.name,
                    },
                    setSelectedStoreOverride,
                    navigate
                )
            );
        } finally {
            setSwitchingId(null);
        }
    };

    return (
        <div className="sm-active-store-slot">
            <span className="sm-nav-divider d-none d-xl-inline-block me-3" aria-hidden="true" />
            <Dropdown className="sm-active-store-dropdown">
                <Dropdown.Toggle
                    as="button"
                    type="button"
                    id="sm-active-store-toggle"
                    className="sm-active-store-btn"
                    title={fullTooltip}
                    disabled={switchingId !== null}
                >
                    <span className="sm-active-store-icon" aria-hidden="true">
                        <FontAwesomeIcon icon={faStore} />
                    </span>

                    <span className="sm-active-store-copy">
                        <span className="sm-active-store-name">
                            {storeTitle || displayName}
                        </span>
                        <span className="sm-active-store-sub">
                            {subtitleText}
                        </span>
                    </span>

                    <span className="sm-active-store-right">
                        <span className="sm-active-store-pill">
                            <span className="sm-active-store-dot" />
                            <span>Active</span>
                        </span>
                        {authorizedStores.length > 1 && (
                            <FontAwesomeIcon
                                icon={faChevronDown}
                                className="sm-active-store-chevron"
                            />
                        )}
                    </span>
                </Dropdown.Toggle>

                <Dropdown.Menu className="sm-active-store-menu">
                    <div className="sm-active-store-menu-head">
                        Active Store &amp; Branch
                    </div>
                    {authorizedStores.map((store) => {
                        const isCurrent = store.id === activeStore.id;
                        const itemName = store?.attributes?.name || "";
                        const parsed = parseStoreName(itemName);
                        return (
                            <Dropdown.Item
                                key={store.id}
                                as="button"
                                type="button"
                                className={`sm-active-store-option${
                                    isCurrent ? " is-active" : ""
                                }`}
                                onClick={() => handleSwitchStore(store)}
                                disabled={switchingId !== null}
                                title={itemName}
                            >
                                <span className="sm-active-store-option-main">
                                    <span className="sm-active-store-option-title">
                                        {parsed.storeTitle || itemName}
                                    </span>
                                    {parsed.branchLabel ? (
                                        <span className="sm-active-store-option-branch">
                                            {parsed.branchLabel}
                                        </span>
                                    ) : null}
                                </span>
                                {isCurrent ? (
                                    <span className="sm-active-store-option-badge">
                                        <FontAwesomeIcon icon={faCheck} />
                                        <span>Active</span>
                                    </span>
                                ) : null}
                            </Dropdown.Item>
                        );
                    })}
                </Dropdown.Menu>
            </Dropdown>
        </div>
    );
};

export default ActiveStoreIndicator;
