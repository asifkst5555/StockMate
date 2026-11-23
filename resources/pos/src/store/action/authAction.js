import axios from "axios";
import apiConfig from "../../config/apiConfig";
import { environment } from "../../config/environment";
import { authActionType, Tokens, toastType, apiBaseURL, ROLES, permissionMappings, configActionType } from "../../constants";
import { fetchPermissions } from "./permissionAction";
import { addToast } from "./toastAction";
import { fetchFrontSetting } from "./frontSettingAction";
import { setLanguage } from "./changeLanguageAction";
import { getFormattedMessage } from "../../shared/sharedMethod";
import { fetchLanguageData, updateLanguage } from '../../store/action/updateLanguageAction';
import { fetchConfig } from "./configAction";
import Cookies from 'js-cookie';

const mapPermissionToRoute = (permission) => {
    const permissionKey = permission.toLowerCase();
    if (permissionMappings.hasOwnProperty(permissionKey)) {
        return permissionMappings[permissionKey];
    } else {
        const entity = permissionKey.split("_").slice(1).join("-");
        return `/app/user/${entity}`;
    }
};

const completeLogin = async (response, dispatch, navigate) => {
    localStorage.setItem(Tokens.ADMIN, response.data.data.token);
    localStorage.setItem(
        Tokens.GET_PERMISSIONS,
        response.data.data.permissions
    );
    const userImageUrl = response.data.data.user.image_url || "";
    localStorage.setItem(Tokens.USER, response.data.data.user.email);
    localStorage.setItem(Tokens.IMAGE, userImageUrl);
    localStorage.setItem(Tokens.USER_IMAGE_URL, userImageUrl);
    localStorage.removeItem(Tokens.UPDATED_EMAIL);
    localStorage.removeItem(Tokens.UPDATED_FIRST_NAME);
    localStorage.removeItem(Tokens.UPDATED_LAST_NAME);
    localStorage.setItem(
        Tokens.FIRST_NAME,
        response.data.data.user.first_name
    );
    localStorage.setItem(
        Tokens.LANGUAGE,
        response.data.data.user.language
    );
    localStorage.setItem(
        Tokens.LAST_NAME,
        response.data.data.user.last_name
    );
    localStorage.setItem(
        "loginUserArray",
        JSON.stringify(response.data.data.user)
    );
    localStorage.setItem(
        "user_time",
        Date.now() + response.data.data.expires_at * 60 * 1000
    );
    Cookies.set('authToken', response.data.data.token, { expires: new Date(new Date().getTime() + response.data.data.expires_at * 60 * 1000) });
    dispatch({
        type: authActionType.LOGIN_USER,
        payload: response.data.data,
    });
    dispatch(setLanguage(response.data.data.user.language));
    localStorage.setItem(
        Tokens.UPDATED_LANGUAGE,
        response.data.data.user.language
    );

    const userPermissions = response.data.data.permissions;
    const mappedRoutes = userPermissions.map(mapPermissionToRoute);
    if (response.data.data.roles == ROLES.SUPER_ADMIN) {
        navigate("/app/admin/dashboard");
    } else {
        if (mappedRoutes && mappedRoutes.length > 0) {
            if (userPermissions.includes("manage_dashboard")) {
                navigate("/app/user/dashboard");
            } else if (
                mappedRoutes.length === 1 &&
                userPermissions.includes("manage_pos_screen")
            ) {
                navigate("/app/user/pos");
            } else {
                navigate(mappedRoutes[0]);
            }
        } else {
            navigate("/app/user/dashboard");
        }
        dispatch(fetchPermissions());
        dispatch(fetchConfig());
    }
    dispatch(fetchFrontSetting());
    dispatch(
        addToast({ text: getFormattedMessage("login.success.message") })
    );

    if (response.data.data.user.language) {
        try {
            await dispatch(fetchLanguageData(response.data.data.user.language_id));
        } catch (error) {
            console.error('Error fetching language data:', error);
        }
    }
};

export const loginAction = (user, navigate, setLoading) => async (dispatch) => {
    try {
        const response = await apiConfig.post("login", user);
        await completeLogin(response, dispatch, navigate);
    } catch ({ response }) {
        dispatch(
            addToast({ text: response?.data?.message, type: toastType.ERROR })
        );
        setLoading(false);
    }
};

export const demoStoreAdminQuickLoginAction = (navigate, setDemoLoading) => async (dispatch) => {
    try {
        const xsrfToken = Cookies.get("XSRF-TOKEN");
        const csrfMeta = document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute("content");
        const headers = {
            "X-Requested-With": "XMLHttpRequest",
            Accept: "application/json",
        };
        if (xsrfToken) {
            headers["X-XSRF-TOKEN"] = xsrfToken;
        } else if (csrfMeta) {
            headers["X-CSRF-TOKEN"] = csrfMeta;
        }

        const response = await axios.post(
            `${environment.URL}/demo-quick-login`,
            {},
            {
                withCredentials: true,
                headers,
            }
        );
        await completeLogin(response, dispatch, navigate);
    } catch (error) {
        dispatch(
            addToast({
                text:
                    error?.response?.data?.message ||
                    "Demo quick login failed. Please try again.",
                type: toastType.ERROR,
            })
        );
        setDemoLoading(false);
    }
};

export const quickLoginAction = (role, navigate, setQuickLoading) => async (dispatch) => {
    try {
        const csrfToken = document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute("content");
        const response = await axios.post(
            `${environment.URL}/local-quick-login`,
            { role },
            {
                withCredentials: true,
                headers: {
                    "X-CSRF-TOKEN": csrfToken || "",
                    "X-Requested-With": "XMLHttpRequest",
                    Accept: "application/json",
                },
            }
        );
        await completeLogin(response, dispatch, navigate);
    } catch (error) {
        dispatch(
            addToast({
                text: error?.response?.data?.message || "Quick login failed.",
                type: toastType.ERROR,
            })
        );
        setQuickLoading(null);
    }
};

export const logoutAction = (token, navigate) => async (dispatch) => {
    const authToken =
        Cookies.get("authToken") ||
        localStorage.getItem(Tokens.ADMIN) ||
        (typeof token === "string" ? token : "");
    const xsrfToken = Cookies.get("XSRF-TOKEN");
    const csrfMeta = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute("content");

    const headers = {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
    };
    if (authToken) {
        headers.Authorization = `Bearer ${authToken}`;
    }
    if (xsrfToken) {
        headers["X-XSRF-TOKEN"] = xsrfToken;
    } else if (csrfMeta) {
        headers["X-CSRF-TOKEN"] = csrfMeta;
    }

    await axios
        .post(`${environment.URL}/api/logout`, {}, { withCredentials: true, headers })
        .then(() => {
            localStorage.removeItem(Tokens.ADMIN);
            localStorage.removeItem(Tokens.USER);
            localStorage.removeItem(Tokens.GET_PERMISSIONS);
            localStorage.removeItem(Tokens.IMAGE);
            localStorage.removeItem(Tokens.FIRST_NAME);
            localStorage.removeItem(Tokens.LAST_NAME);
            localStorage.removeItem("loginUserArray");
            localStorage.removeItem("user_time");
            localStorage.removeItem(Tokens.UPDATED_EMAIL);
            localStorage.removeItem(Tokens.UPDATED_FIRST_NAME);
            localStorage.removeItem(Tokens.UPDATED_LAST_NAME);
            localStorage.removeItem(Tokens.USER_IMAGE_URL);
            Cookies.remove("authToken");
            dispatch({
                type: authActionType.LOGIN_USER,
                payload: {},
            });
            dispatch({
                type: configActionType.FETCH_ALL_CONFIG,
                payload: "",
            });
            dispatch(
                addToast({
                    text: getFormattedMessage("logout.success.message"),
                })
            );
            window.location.replace("/app/login");
        })
        .catch(({ response }) => {
            dispatch(
                addToast({
                    text: response?.data?.message || "Logout failed.",
                    type: toastType.ERROR,
                })
            );
        });
};

export const forgotPassword = (user) => async (dispatch) => {
    await apiConfig
        .post(apiBaseURL.ADMIN_FORGOT_PASSWORD, user)
        .then((response) => {
            dispatch({
                type: authActionType.ADMIN_FORGOT_PASSWORD,
                payload: response?.data?.message,
            });
            dispatch(
                addToast({
                    text: getFormattedMessage(
                        "forgot-password-form.success.reset-link.label"
                    ),
                })
            );
        })
        .catch(({ response }) => {
            dispatch({ type: toastType.ERROR, payload: response?.data?.message });
            dispatch(
                addToast({ text: response?.data?.message, type: toastType.ERROR })
            );
        });
};

export const resetPassword = (user, navigate) => async (dispatch) => {
    await apiConfig
        .post(apiBaseURL.ADMIN_RESET_PASSWORD, user)
        .then((response) => {
            dispatch({
                type: authActionType.ADMIN_RESET_PASSWORD,
                payload: user,
            });
            dispatch(
                addToast({
                    text: getFormattedMessage(
                        "reset-password.success.update.message"
                    ),
                })
            );
            navigate("/app/login");
        })
        .catch(({ response }) => {
            dispatch(
                addToast({ text: response?.data?.message, type: toastType.ERROR })
            );
        });
};


export const register = (data, navigate, setDisable) => async (dispatch) => {
    await apiConfig
        .post("register", data)
        .then(() => {
            navigate("/app/login");
            dispatch(
                addToast({
                    text: getFormattedMessage("register.success.message"),
                })
            );
        })
        .catch(({ response }) => {
            dispatch(
                addToast({ text: response?.data?.message, type: toastType.ERROR })
            );
        })
        .finally(() => {
            setDisable(false);
        });
};