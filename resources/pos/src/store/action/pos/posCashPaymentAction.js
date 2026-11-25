import {
    apiBaseURL,
    posCashPaymentActionType,
    toastType,
} from "../../../constants";
import apiConfig from "../../../config/apiConfig";
import { addToast } from "../toastAction";
import { fetchBrandClickable } from "./posAllProductAction";
import { getFormattedMessage } from "../../../shared/sharedMethod";
import { setLoading } from "../loadingAction";
import { fetchHoldLists } from "./HoldListAction";

export const posCashPaymentAction =
    (
        detailsCash,
        setUpdateProducts,
        setModalShowPaymentSlip,
        filterData,
        printSlip = false,
        isLoading = true,
        options = {}
    ) =>
    async (dispatch) => {
        if (isLoading) {
            dispatch(setLoading(true));
        }

        const url = apiBaseURL.CASH_PAYMENT;
        const requestConfig = options?.idempotencyKey
            ? { headers: { "Idempotency-Key": options.idempotencyKey } }
            : undefined;

        try {
            const response = await apiConfig.post(url, detailsCash, requestConfig);
            dispatch({
                type: posCashPaymentActionType.POS_CASH_PAYMENT,
                payload: response.data.data,
            });
            dispatch(
                addToast({
                    text: getFormattedMessage("sale.success.create.message"),
                })
            );
            setUpdateProducts([]);
            if (typeof options?.onSuccess === "function") {
                options.onSuccess(response.data.data);
            }
            if (printSlip && typeof setModalShowPaymentSlip === "function") {
                setModalShowPaymentSlip(true);
            }
            if (filterData?.selectedOption?.value) {
                dispatch(
                    fetchBrandClickable(
                        filterData.brandId,
                        filterData.categoryId,
                        filterData.selectedOption.value
                    )
                );
            }
            if (isLoading) {
                dispatch(setLoading(false));
                dispatch(fetchHoldLists());
            }
            return true;
        } catch (error) {
            const errorMessage =
                error?.response?.data?.message ||
                error?.message ||
                "Checkout failed. Please try again.";
            dispatch(
                addToast({
                    text: errorMessage,
                    type: toastType.ERROR,
                })
            );
            if (isLoading) {
                dispatch(setLoading(false));
            }
            return false;
        }
    };
