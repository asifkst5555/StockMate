import apiConfig from "../../config/apiConfig";
import { apiBaseURL, adjustMentActionType, toastType } from "../../constants";
import { addToast } from "./toastAction";
import {
    addInToTotalRecord,
    removeFromTotalRecord,
    setTotalRecord,
} from "./totalRecordAction";
import { setLoading } from "./loadingAction";
import requestParam from "../../shared/requestParam";
import { getFormattedMessage } from "../../shared/sharedMethod";
import { callSaleApi } from "./saleApiAction";
import { setSavingButton } from "./saveButtonAction";
import { callFetchDataApi } from "./updateBrand";

export const fetchAdjustments =
    (filter = {}, isLoading = true) =>
    async (dispatch) => {
        if (isLoading) {
            dispatch(setLoading(true));
        }
        const admin = true;
        let url = apiBaseURL.ADJUSTMENTS;
        if (
            !_.isEmpty(filter) &&
            (filter.page || filter.pageSize || filter.search || filter.warehouse_id)
        ) {
            url += requestParam(filter, admin, null, null, url);
        }
        try {
            const response = await apiConfig.get(url);
            dispatch({
                type: adjustMentActionType.FETCH_ADJUSTMENTS,
                payload: response.data.data,
            });
            dispatch(
                setTotalRecord(
                    response.data.meta?.total !== undefined &&
                        response.data.meta.total >= 0
                        ? response.data.meta.total
                        : response.data.data?.total || 0
                )
            );
            dispatch(callSaleApi(false));
            return true;
        } catch (error) {
            const message = error?.response?.data?.message || error?.message;
            dispatch(
                addToast({
                    text: message,
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

export const fetchAdjustment =
    (adjustmentId, isLoading = true) =>
    async (dispatch) => {
        if (isLoading) {
            dispatch(setLoading(true));
        }
        try {
            const response = await apiConfig.get(
                apiBaseURL.ADJUSTMENTS + "/" + adjustmentId + "/edit"
            );
            dispatch({
                type: adjustMentActionType.FETCH_ADJUSTMENT,
                payload: response.data.data,
            });
            return true;
        } catch (error) {
            const message = error?.response?.data?.message || error?.message;
            dispatch(
                addToast({
                    text: message,
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

export const addAdjustment = (adjustment, navigate) => async (dispatch) => {
    dispatch(setSavingButton(true));
    try {
        const response = await apiConfig.post(apiBaseURL.ADJUSTMENTS, adjustment);
        dispatch({
            type: adjustMentActionType.ADD_ADJUSTMENTS,
            payload: response.data.data,
        });
        dispatch(
            addToast({
                text: getFormattedMessage("Adjustment.success.create.message"),
            })
        );
        dispatch(addInToTotalRecord(1));
        if (navigate) {
            navigate("/app/user/adjustments");
        }
        return true;
    } catch (error) {
        const message = error?.response?.data?.message || error?.message;
        dispatch(
            addToast({ text: message, type: toastType.ERROR })
        );
        return false;
    } finally {
        dispatch(setSavingButton(false));
    }
};

export const editAdjustment =
    (adjustmentId, adjustment, navigate) => async (dispatch) => {
        dispatch(setSavingButton(true));
        try {
            const response = await apiConfig.patch(
                apiBaseURL.ADJUSTMENTS + "/" + adjustmentId,
                adjustment
            );
            dispatch(
                addToast({
                    text: getFormattedMessage("Adjustment.success.edit.message"),
                })
            );
            dispatch({
                type: adjustMentActionType.EDIT_ADJUSTMENTS,
                payload: response.data.data,
            });
            if (navigate) {
                navigate("/app/user/adjustments");
            }
            return true;
        } catch (error) {
            const message = error?.response?.data?.message || error?.message;
            dispatch(
                addToast({
                    text: message,
                    type: toastType.ERROR,
                })
            );
            return false;
        } finally {
            dispatch(setSavingButton(false));
        }
    };

export const deleteAdjustment = (userId) => async (dispatch) => {
    try {
        await apiConfig.delete(apiBaseURL.ADJUSTMENTS + "/" + userId);
        dispatch(removeFromTotalRecord(1));
        dispatch({
            type: adjustMentActionType.DELETE_ADJUSTMENT,
            payload: userId,
        });
        dispatch(callFetchDataApi(true));
        dispatch(
            addToast({
                text: getFormattedMessage("Adjustment.success.delete.message"),
            })
        );
        return true;
    } catch (error) {
        const message = error?.response?.data?.message || error?.message;
        dispatch(
            addToast({ text: message, type: toastType.ERROR })
        );
        return false;
    }
};
