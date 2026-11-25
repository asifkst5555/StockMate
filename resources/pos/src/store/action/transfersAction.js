import apiConfig from "../../config/apiConfig";
import { apiBaseURL, transferActionType, toastType } from "../../constants";
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

export const fetchTransfers =
    (filter = {}, isLoading = true) =>
    async (dispatch) => {
        if (isLoading) {
            dispatch(setLoading(true));
        }
        let url = apiBaseURL.TRANSFERS;
        if (
            !_.isEmpty(filter) &&
            (filter.page ||
                filter.pageSize ||
                filter.search ||
                filter.order_By ||
                filter.created_at ||
                filter.status ||
                filter.from_warehouse_id ||
                filter.to_warehouse_id)
        ) {
            url += requestParam(filter, null, null, null, url);
        }
        try {
            const response = await apiConfig.get(url);
            dispatch({
                type: transferActionType.FETCH_TRANSFERS,
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

export const addTransfer = (transfer, navigate) => async (dispatch) => {
    dispatch(setSavingButton(true));
    try {
        const response = await apiConfig.post(apiBaseURL.TRANSFERS, transfer);
        dispatch({
            type: transferActionType.ADD_TRANSFER,
            payload: response.data.data,
        });
        dispatch(
            addToast({
                text: getFormattedMessage(
                    "transfer.success.create.message"
                ),
            })
        );
        dispatch(addInToTotalRecord(1));
        if (navigate) {
            navigate("/app/user/transfers");
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

export const fetchTransfer =
    (transferId, singleTransfer, isLoading = true) =>
    async (dispatch) => {
        if (isLoading) {
            dispatch(setLoading(true));
        }
        try {
            const response = await apiConfig.get(
                apiBaseURL.TRANSFERS + "/" + transferId + "/edit",
                singleTransfer
            );
            dispatch({
                type: transferActionType.FETCH_TRANSFER,
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

export const editTransfer =
    (transferId, transfer, navigate) => async (dispatch) => {
        dispatch(setSavingButton(true));
        try {
            const response = await apiConfig.patch(
                apiBaseURL.TRANSFERS + "/" + transferId,
                transfer
            );
            dispatch(
                addToast({
                    text: getFormattedMessage("transfer.success.edit.message"),
                })
            );
            dispatch({
                type: transferActionType.EDIT_TRANSFER,
                payload: response.data.data,
            });
            if (navigate) {
                navigate("/app/user/transfers");
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

export const deletetransfer = (transferId) => async (dispatch) => {
    try {
        await apiConfig.delete(apiBaseURL.TRANSFERS + "/" + transferId);
        dispatch(removeFromTotalRecord(1));
        dispatch(
            addToast({
                text: getFormattedMessage(
                    "transfer.success.delete.message"
                ),
            })
        );
        dispatch(callFetchDataApi(true));
        dispatch({
            type: transferActionType.DELETE_TRANSFER,
            payload: transferId,
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
    }
};
