import apiConfig from "../../config/apiConfig";
import { apiBaseURL, saleReturnActionType, toastType } from "../../constants";
import { addToast } from "./toastAction";
import {
    addInToTotalRecord,
    removeFromTotalRecord,
    setTotalRecord,
} from "./totalRecordAction";
import { setLoading } from "./loadingAction";
import requestParam from "../../shared/requestParam";
import { getFormattedMessage } from "../../shared/sharedMethod";
import { setSavingButton } from "./saveButtonAction";
import { callFetchDataApi } from "./updateBrand";

const extractErrorMessage = (error) =>
    error?.response?.data?.message || error?.message;

export const fetchSalesReturn =
    (filter = {}, isLoading = true) =>
    async (dispatch) => {
        if (isLoading) {
            dispatch(setLoading(true));
        }
        const admin = true;
        let url = apiBaseURL.SALE_RETURN;
        if (
            filter &&
            Object.keys(filter).length > 0 &&
            (filter.page ||
                filter.pageSize ||
                filter.search ||
                filter.order_By ||
                filter.created_at)
        ) {
            url += requestParam(filter, admin, null, null, url);
        }
        try {
            const response = await apiConfig.get(url);
            dispatch({
                type: saleReturnActionType.FETCH_SALES_RETURN,
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

export const fetchSaleReturn =
    (saleId, isSaleReturnFromSale, isLoading = true) =>
    async (dispatch) => {
        if (isLoading) {
            dispatch(setLoading(true));
        }
        try {
            const response = await apiConfig.get(
                !isSaleReturnFromSale
                    ? apiBaseURL.SALE_RETURN + "/" + saleId + "/edit"
                    : apiBaseURL.EDIT_SALE_FROM_SALE + "/" + saleId
            );
            dispatch({
                type: saleReturnActionType.FETCH_SALE_RETURN,
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

export const addSaleReturn = (sale, navigate) => async (dispatch) => {
    dispatch(setSavingButton(true));
    try {
        const response = await apiConfig.post(apiBaseURL.SALE_RETURN, sale);
        dispatch({
            type: saleReturnActionType.ADD_SALE_RETURN,
            payload: response.data.data,
        });
        dispatch(
            addToast({
                text: getFormattedMessage(
                    "sale-return.success.create.message"
                ),
            })
        );
        dispatch(addInToTotalRecord(1));
        if (typeof navigate === "function") {
            navigate("/app/user/sale-return");
        }
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

export const editSaleReturn = (saleId, sale, navigate) => async (dispatch) => {
    dispatch(setSavingButton(true));
    try {
        await apiConfig.patch(apiBaseURL.SALE_RETURN + "/" + saleId, sale);
        dispatch(
            addToast({
                text: getFormattedMessage(
                    "sale-return.success.edit.message"
                ),
            })
        );
        if (typeof navigate === "function") {
            navigate("/app/user/sale-return");
        }
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

export const deleteSaleReturn = (userId) => async (dispatch) => {
    try {
        await apiConfig.delete(apiBaseURL.SALE_RETURN + "/" + userId);
        dispatch(removeFromTotalRecord(1));
        dispatch({
            type: saleReturnActionType.DELETE_SALE_RETURN,
            payload: userId,
        });
        dispatch(callFetchDataApi(true));
        dispatch(
            addToast({
                text: getFormattedMessage(
                    "sale-return.success.delete.message"
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
