import React, { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useDispatch, useSelector } from "react-redux";
import * as EmailValidator from "email-validator";
import {
    demoStoreAdminQuickLoginAction,
    loginAction,
    quickLoginAction,
} from "../../store/action/authAction";
import TabTitle from "../../shared/tab-title/TabTitle";
import { ROLES } from "../../constants";
import {
    getFormattedMessage,
    placeholderText,
} from "../../shared/sharedMethod";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import {
    faBoxesStacked,
    faCashRegister,
    faChartLine,
    faEye,
    faEyeSlash,
    faRocket,
    faStore,
    faUserShield,
} from "@fortawesome/free-solid-svg-icons";
import Cookies from "js-cookie";
import { fetchLanguages } from "../../store/action/languageAction";
import LanguageLayout from "./LanguageLayout";
import { fetchFrontCms } from "../../store/action/frontCmsAction";
import "./Login.css";

const Login = () => {
    const navigate = useNavigate();
    const dispatch = useDispatch();
    const { loginUser, frontCms } = useSelector((state) => state);
    const [showPassword, setShowPassword] = useState(false);
    const [loading, setLoading] = useState(false);
    const [demoLoading, setDemoLoading] = useState(false);
    const [quickLoading, setQuickLoading] = useState(null);
    const token = Cookies.get("authToken");

    const [loginInputs, setLoginInputs] = useState({
        email: "",
        password: "",
    });

    useEffect(() => {
        dispatch(fetchFrontCms());
        dispatch(fetchLanguages());
        if (token) {
            if (loginUser.roles === ROLES.SUPER_ADMIN) {
                navigate("/app/admin/dashboard");
            } else {
                navigate("/app/user/dashboard");
            }
        }
    }, []);

    const [errors, setErrors] = useState({
        email: "",
        password: "",
    });

    const handleValidation = () => {
        let errorss = {};
        let isValid = false;
        if (!EmailValidator.validate(loginInputs["email"])) {
            if (!loginInputs["email"]) {
                errorss["email"] = getFormattedMessage(
                    "globally.input.email.validate.label"
                );
            } else {
                errorss["email"] = getFormattedMessage(
                    "globally.input.email.valid.validate.label"
                );
            }
        } else if (!loginInputs["password"]) {
            errorss["password"] = getFormattedMessage(
                "user.input.password.validate.label"
            );
        } else {
            isValid = true;
        }
        setErrors(errorss);
        setLoading(false);
        return isValid;
    };

    const prepareFormData = () => {
        const formData = new FormData();
        formData.append("email", loginInputs.email);
        formData.append("password", loginInputs.password);
        formData.append(
            "language_code",
            localStorage.getItem("updated_language")
        );
        return formData;
    };

    const onLogin = async (e) => {
        e.preventDefault();
        const valid = handleValidation();
        if (valid) {
            setLoading(true);
            dispatch(
                loginAction(prepareFormData(loginInputs), navigate, setLoading)
            );
            setLoginInputs({
                email: "",
                password: "",
            });
        }
    };

    const handleChange = (e) => {
        e.persist();
        setLoginInputs((inputs) => ({
            ...inputs,
            [e.target.name]: e.target.value,
        }));
        setErrors({ email: "", password: "" });
    };

    const handleHideShowPassword = () => {
        setShowPassword(!showPassword);
    };

    const onDemoStoreAdminLogin = () => {
        if (loading || demoLoading || quickLoading !== null) {
            return;
        }
        setDemoLoading(true);
        dispatch(demoStoreAdminQuickLoginAction(navigate, setDemoLoading));
    };

    const onQuickLogin = (role) => {
        if (loading || demoLoading || quickLoading) {
            return;
        }
        setQuickLoading(role);
        dispatch(quickLoginAction(role, navigate, setQuickLoading));
    };

    const logoSrc =
        frontCms?.value?.app_logo || "/images/logo/logo-black.webp";

    const weeklyBars = [
        { day: "Mon", height: "44%", tone: "" },
        { day: "Tue", height: "62%", tone: "navy" },
        { day: "Wed", height: "54%", tone: "" },
        { day: "Thu", height: "76%", tone: "navy" },
        { day: "Fri", height: "94%", tone: "orange" },
        { day: "Sat", height: "84%", tone: "orange" },
        { day: "Sun", height: "68%", tone: "navy" },
    ];

    return (
        <div className="sm-login-page">
            <TabTitle title={placeholderText("login-form.login-btn.label")} />

            <div className="sm-login-lang">
                <LanguageLayout />
            </div>

            {/* LEFT PANEL (52%) — Brand & Realistic Product Preview */}
            <aside className="sm-login-brand">
                <div className="sm-login-brand-inner">
                    <div className="sm-brand-top">
                        <a href="/" className="sm-brand-logo-link">
                            <img
                                src={logoSrc}
                                alt="StockMate"
                                className="sm-brand-logo-img"
                            />
                        </a>
                        <span className="sm-brand-pill">
                            Inventory &amp; POS Platform
                        </span>
                    </div>

                    <div className="sm-brand-copy">
                        <h1 className="sm-brand-headline">
                            Take Control of <span>Your Business.</span>
                        </h1>
                        <p className="sm-brand-subtitle">
                            Manage inventory, sales, purchases and multiple
                            stores from one powerful platform.
                        </p>
                    </div>

                    {/* Realistic StockMate Workspace Preview */}
                    <div className="sm-preview-shell" aria-hidden="true">
                        <div className="sm-preview-topbar">
                            <div className="sm-preview-left">
                                <div className="sm-preview-dots">
                                    <span />
                                    <span />
                                    <span />
                                </div>
                                <span className="sm-preview-title">
                                    StockMate · Main Warehouse
                                </span>
                            </div>
                            <span className="sm-preview-status">
                                <span className="sm-status-dot" />
                                POS Live Sync
                            </span>
                        </div>

                        <div className="sm-preview-content">
                            <div className="sm-preview-sidebar">
                                <div className="sm-nav-pill active">
                                    <FontAwesomeIcon icon={faChartLine} />
                                    <span>Overview</span>
                                </div>
                                <div className="sm-nav-pill">
                                    <FontAwesomeIcon icon={faCashRegister} />
                                    <span>POS</span>
                                </div>
                                <div className="sm-nav-pill">
                                    <FontAwesomeIcon icon={faBoxesStacked} />
                                    <span>Stock</span>
                                </div>
                                <div className="sm-nav-pill">
                                    <FontAwesomeIcon icon={faStore} />
                                    <span>Stores</span>
                                </div>
                            </div>

                            <div className="sm-preview-main">
                                <div className="sm-kpi-grid">
                                    <div className="sm-kpi-card">
                                        <div className="sm-kpi-label">
                                            Daily Sales
                                        </div>
                                        <div className="sm-kpi-value">
                                            $14,820
                                        </div>
                                        <div className="sm-kpi-meta">
                                            +12.4% vs avg
                                        </div>
                                    </div>
                                    <div className="sm-kpi-card">
                                        <div className="sm-kpi-label">
                                            Total Products
                                        </div>
                                        <div className="sm-kpi-value">
                                            1,248
                                        </div>
                                        <div className="sm-kpi-meta muted">
                                            98% in stock
                                        </div>
                                    </div>
                                    <div className="sm-kpi-card">
                                        <div className="sm-kpi-label">
                                            Active Stores
                                        </div>
                                        <div className="sm-kpi-value">4</div>
                                        <div className="sm-kpi-meta muted">
                                            All synced
                                        </div>
                                    </div>
                                </div>

                                <div className="sm-preview-lower">
                                    <div className="sm-subpanel">
                                        <div className="sm-subpanel-head">
                                            <span className="sm-subpanel-title">
                                                Sales Performance
                                            </span>
                                            <span className="sm-subpanel-tag">
                                                $48.2k / 7d
                                            </span>
                                        </div>
                                        <div className="sm-bars-wrap">
                                            {weeklyBars.map((bar) => (
                                                <div
                                                    key={bar.day}
                                                    className="sm-bar-col"
                                                >
                                                    <div
                                                        className={`sm-bar-fill ${bar.tone}`}
                                                        style={{
                                                            height: bar.height,
                                                        }}
                                                    />
                                                    <span className="sm-bar-day">
                                                        {bar.day}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                        <div className="sm-chart-summary">
                                            <span>
                                                Peak: <strong>Fri ($9.4k)</strong>
                                            </span>
                                            <span>
                                                Avg Order: <strong>$68.50</strong>
                                            </span>
                                        </div>
                                    </div>

                                    <div className="sm-subpanel">
                                        <div className="sm-subpanel-head">
                                            <span className="sm-subpanel-title">
                                                POS &amp; Stock
                                            </span>
                                            <span className="sm-subpanel-tag">
                                                Live
                                            </span>
                                        </div>
                                        <div className="sm-activity-list">
                                            <div className="sm-activity-row">
                                                <div>
                                                    <div className="sm-activity-name">
                                                        POS #1048
                                                    </div>
                                                    <div className="sm-activity-sub">
                                                        Cash · 4 items
                                                    </div>
                                                </div>
                                                <div className="sm-activity-amt">
                                                    $245.00
                                                </div>
                                            </div>
                                            <div className="sm-activity-row">
                                                <div>
                                                    <div className="sm-activity-name">
                                                        POS #1047
                                                    </div>
                                                    <div className="sm-activity-sub">
                                                        Split · 2 items
                                                    </div>
                                                </div>
                                                <div className="sm-activity-amt">
                                                    $118.50
                                                </div>
                                            </div>
                                            <div>
                                                <div className="sm-activity-row">
                                                    <span className="sm-activity-sub">
                                                        Healthy Stock Level
                                                    </span>
                                                    <span className="sm-activity-name">
                                                        82%
                                                    </span>
                                                </div>
                                                <div className="sm-stock-bar-bg">
                                                    <div className="sm-stock-bar-fill" />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="sm-feature-list">
                        <div className="sm-feature-item">
                            <div className="sm-feature-icon">
                                <FontAwesomeIcon icon={faBoxesStacked} />
                            </div>
                            <span className="sm-feature-text">
                                Smart Inventory Management
                            </span>
                        </div>
                        <div className="sm-feature-item">
                            <div className="sm-feature-icon">
                                <FontAwesomeIcon icon={faCashRegister} />
                            </div>
                            <span className="sm-feature-text">
                                Powerful Point of Sale
                            </span>
                        </div>
                        <div className="sm-feature-item">
                            <div className="sm-feature-icon">
                                <FontAwesomeIcon icon={faStore} />
                            </div>
                            <span className="sm-feature-text">
                                Multi-Store Business Control
                            </span>
                        </div>
                    </div>
                </div>
            </aside>

            {/* RIGHT PANEL (48%) — Authentication Card */}
            <main className="sm-login-panel">
                <div className="sm-mobile-brand">
                    <a href="/">
                        <img src={logoSrc} alt="StockMate" />
                    </a>
                    <p>Inventory Management &amp; POS</p>
                </div>

                <div className="sm-login-card">
                    <h2 className="sm-card-heading">Welcome back</h2>
                    <p className="sm-card-subtitle">
                        Sign in to continue to your StockMate workspace.
                    </p>

                    <form onSubmit={onLogin} noValidate>
                        <div className="sm-field-group">
                            <div className="sm-field-header">
                                <label
                                    htmlFor="sm-login-email"
                                    className="sm-field-label"
                                >
                                    Email Address
                                </label>
                            </div>
                            <input
                                id="sm-login-email"
                                placeholder={placeholderText(
                                    "globally.input.email.placeholder.label"
                                )}
                                required
                                value={loginInputs.email}
                                className={`sm-input${
                                    errors["email"] ? " is-invalid" : ""
                                }`}
                                type="email"
                                name="email"
                                autoComplete="email"
                                onChange={handleChange}
                            />
                            {errors["email"] ? (
                                <span className="sm-error-text">
                                    {errors["email"]}
                                </span>
                            ) : null}
                        </div>

                        <div className="sm-field-group">
                            <div className="sm-field-header">
                                <label
                                    htmlFor="sm-login-password"
                                    className="sm-field-label"
                                >
                                    Password
                                </label>
                                <Link
                                    to="/app/forgot-password"
                                    className="sm-forgot-link"
                                >
                                    {getFormattedMessage(
                                        "login-form.forgot-password.label"
                                    )}
                                </Link>
                            </div>
                            <div className="sm-input-wrap">
                                <input
                                    id="sm-login-password"
                                    className={`sm-input has-toggle${
                                        errors["password"] ? " is-invalid" : ""
                                    }`}
                                    type={showPassword ? "text" : "password"}
                                    name="password"
                                    placeholder={placeholderText(
                                        "user.input.password.placeholder.label"
                                    )}
                                    autoComplete="current-password"
                                    required
                                    value={loginInputs.password}
                                    onChange={handleChange}
                                />
                                <button
                                    type="button"
                                    className="sm-password-toggle"
                                    onClick={handleHideShowPassword}
                                    aria-label={
                                        showPassword
                                            ? "Hide password"
                                            : "Show password"
                                    }
                                >
                                    <FontAwesomeIcon
                                        icon={showPassword ? faEye : faEyeSlash}
                                    />
                                </button>
                            </div>
                            {errors["password"] ? (
                                <span className="sm-error-text">
                                    {errors["password"]}
                                </span>
                            ) : null}
                        </div>

                        <button
                            type="submit"
                            className="sm-btn-signin"
                            disabled={loading || demoLoading || quickLoading !== null}
                        >
                            {loading
                                ? getFormattedMessage("globally.loading.label")
                                : "Sign In"}
                        </button>

                        {frontCms?.value?.demo_quick_login_enabled === true && (
                            <div className="sm-demo-quick-wrap">
                                <div className="sm-or-divider" aria-hidden="true">
                                    <span>OR</span>
                                </div>
                                <button
                                    type="button"
                                    className="sm-btn-demo-admin"
                                    disabled={
                                        loading ||
                                        demoLoading ||
                                        quickLoading !== null
                                    }
                                    onClick={onDemoStoreAdminLogin}
                                >
                                    <FontAwesomeIcon
                                        icon={faRocket}
                                        className="sm-btn-demo-admin-icon"
                                    />
                                    <span>
                                        {demoLoading
                                            ? getFormattedMessage(
                                                  "globally.loading.label"
                                              )
                                            : "Quick Login as Store Admin"}
                                    </span>
                                </button>
                            </div>
                        )}

                        <div className="sm-register-row">
                            <span>
                                {getFormattedMessage("not.registered.label")}
                            </span>
                            <Link
                                to="/app/register"
                                className="sm-register-link"
                            >
                                {getFormattedMessage("register.here.label")}
                            </Link>
                        </div>

                        {frontCms?.value?.local_quick_login_enabled === true && (
                            <div className="sm-quick-section">
                                <div className="sm-quick-label">
                                    Developer Quick Access
                                </div>
                                <div className="sm-quick-grid">
                                    <button
                                        type="button"
                                        className="sm-btn-quick sm-btn-quick-superadmin"
                                        disabled={
                                            loading || quickLoading !== null
                                        }
                                        onClick={() =>
                                            onQuickLogin("superadmin")
                                        }
                                    >
                                        <FontAwesomeIcon icon={faUserShield} />
                                        <span>
                                            {quickLoading === "superadmin"
                                                ? getFormattedMessage(
                                                      "globally.loading.label"
                                                  )
                                                : "Super Admin"}
                                        </span>
                                    </button>
                                    <button
                                        type="button"
                                        className="sm-btn-quick sm-btn-quick-admin"
                                        disabled={
                                            loading || quickLoading !== null
                                        }
                                        onClick={() => onQuickLogin("admin")}
                                    >
                                        <FontAwesomeIcon icon={faStore} />
                                        <span>
                                            {quickLoading === "admin"
                                                ? getFormattedMessage(
                                                      "globally.loading.label"
                                                  )
                                                : "Store Admin"}
                                        </span>
                                    </button>
                                </div>
                            </div>
                        )}
                    </form>
                </div>
            </main>
        </div>
    );
};

export default Login;
