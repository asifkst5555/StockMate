import apiConfig from "../../config/apiConfig";
import {
    apiBaseURL,
    purchaseReturnActionType,
    toastType,
} from "../../constants";
import { addToast } from "./toastAction";
import {
    setTotalRecord,
    addInToTotalRecord,
    removeFromTotalRecord,
} from "./totalRecordAction";
import requestParam from "../../shared/requestParam";
import { setLoading } from "./loadingAction";
import { getFormattedMessage } from "../../shared/sharedMethod";
import { setSavingButton } from "./saveButtonAction";
import { callFetchDataApi } from "./updateBrand";

const extractErrorMessage = (error) =>
    error?.response?.data?.message || error?.message;

export const fetchPurchasesReturn =
    (filter = {}, isLoading = true) =>
    async (dispatch) => {
        if (isLoading) {
            dispatch(setLoading(true));
        }
        let url = apiBaseURL.PURCHASES_RETURN;
        if (
            filter &&
            Object.keys(filter).length > 0 &&
            (filter.page ||
                filter.pageSize ||
                filter.search ||
                filter.order_By ||
                filter.created_at)
        ) {
            url += requestParam(filter, null, null, null, url);
        }
        try {
            const response = await apiConfig.get(url);
            dispatch({
                type: purchaseReturnActionType.FETCH_PURCHASES_RETURN,
                payload: response.data.data,
            });
            dispatch(
                setTotalRecord(
                    response.data?.meta?.total !== undefined &&
                        response.data.meta.total >= 0
                        ? response.data.meta.total
                        : response.data?.data?.total
                )
            );
            return true;
        } catch (error) {
            dispatch(
                addToast({
                    text: extractErrorMessage(error),
                    type: toastType.ERROR,
                })
            );
            return false;
        } finally {
            if (isLoading) {
                dispatch(setLoading(false));
            }
        }
    };

export const fetchPurchaseReturn =
    (purchaseId, isPurchaseReturnFromSale = false, isLoading = true) =>
    async (dispatch) => {
        if (isLoading) {
            dispatch(setLoading(true));
        }
        try {
            const response = await apiConfig.get(
                !isPurchaseReturnFromSale
                    ? apiBaseURL.PURCHASES_RETURN + "/" + purchaseId + "/edit"
                    : apiBaseURL.PURCHASE_RETURN_EDIT + "/" + purchaseId
            );
            dispatch({
                type: purchaseReturnActionType.FETCH_PURCHASE_RETURN,
                payload: response.data.data,
            });
            return true;
        } catch (error) {
            dispatch(
                addToast({
                    text: extractErrorMessage(error),
                    type: toastType.ERROR,
                })
            );
            return false;
        } finally {
            if (isLoading) {
                dispatch(setLoading(false));
            }
        }
    };

export const addPurchaseReturn = (purchase, navigate) => async (dispatch) => {
    dispatch(setSavingButton(true));
    try {
        const response = await apiConfig.post(
            apiBaseURL.PURCHASES_RETURN,
            purchase
        );
        dispatch({
            type: purchaseReturnActionType.ADD_PURCHASE_RETURN,
            payload: response.data.data,
        });
        dispatch(
            addToast({
                text: getFormattedMessage(
                    "purchase.return.success.create.message"
                ),
            })
        );
        if (typeof navigate === "function") {
            navigate("/app/user/purchase-return");
        }
        dispatch(addInToTotalRecord(1));
        return true;
    } catch (error) {
        dispatch(
            addToast({ text: extractErrorMessage(error), type: toastType.ERROR })
        );
        return false;
    } finally {
        dispatch(setSavingButton(false));
    }
};

export const editPurchaseReturn =
    (purchaseId, purchase, navigate) => async (dispatch) => {
        dispatch(setSavingButton(true));
        try {
            await apiConfig.put(
                apiBaseURL.PURCHASES_RETURN + "/" + purchaseId,
                purchase
            );
            if (typeof navigate === "function") {
                navigate("/app/user/purchase-return");
            }
            dispatch(
                addToast({
                    text: getFormattedMessage(
                        "purchase.return.success.edit.message"
                    ),
                })
            );
            return true;
        } catch (error) {
            dispatch(
                addToast({
                    text: extractErrorMessage(error),
                    type: toastType.ERROR,
                })
            );
            return false;
        } finally {
            dispatch(setSavingButton(false));
        }
    };

export const deletePurchaseReturn = (purchaseId) => async (dispatch) => {
    try {
        await apiConfig.delete(apiBaseURL.PURCHASES_RETURN + "/" + purchaseId);
        dispatch(removeFromTotalRecord(1));
        dispatch({
            type: purchaseReturnActionType.DELETE_PURCHASE_RETURN,
            payload: purchaseId,
        });
        dispatch(callFetchDataApi(true));
        dispatch(
            addToast({
                text: getFormattedMessage(
                    "purchase.return.success.delete.message"
                ),
            })
        );
        return true;
    } catch (error) {
        dispatch(
            addToast({ text: extractErrorMessage(error), type: toastType.ERROR })
        );
        return false;
    }
};
