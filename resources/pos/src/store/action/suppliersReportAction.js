import { downloadProtectedFile } from '../../shared/downloadProtectedFile';
import apiConfig from "../../config/apiConfig";
import { apiBaseURL, toastType, supplierActionType } from "../../constants";
import requestParam from "../../shared/requestParam";
import { addToast } from "./toastAction";
import { setTotalRecord } from "./totalRecordAction";
import { setLoading } from "./loadingAction";

export const fetchSuppliersReport =
    (filter = {}, isLoading = true) =>
    async (dispatch) => {
        if (isLoading) {
            dispatch(setLoading(true));
        }
        let url = apiBaseURL.SUPPLIERS_REPORT;
        if (
            !_.isEmpty(filter) &&
            (filter.page ||
                filter.pageSize ||
                filter.search ||
                filter.order_By ||
                filter.created_at ||
                filter.warehouse_id)
        ) {
            url += requestParam(filter, null, null, null, url);
        }
        apiConfig
            .get(url)
            .then(async (response) => {
                dispatch({
                    type: supplierActionType.FETCH_SUPPLIERS_REPORT,
                    payload: response.data.data.data,
                });
                dispatch(
                    setTotalRecord(
                        response.data.data.total
                    )
                );
                if (isLoading) {
                    dispatch(setLoading(false));
                }
            })
            .catch(({ response }) => {
                dispatch(
                    addToast({
                        text: response?.data?.message,
                        type: toastType.ERROR,

                if (isLoading) {
                    dispatch(setLoading(false));
                }
            })
                );
            });
    };

export const suppliersPdfAction =
    (supplierId, isLoading = true) =>
    async (dispatch) => {
        if (isLoading) {
            dispatch(setLoading(true));
        }
        apiConfig
            .get(apiBaseURL.SUPPLIER_REPORT_PDF + "/" + supplierId)
            .then(async (response) => {
                await downloadProtectedFile(response.data.data.suppliers_report_pdf_url);
                if (isLoading) {
                    dispatch(setLoading(false));
                }
            })
            .catch(({ response }) => {
                dispatch(
                    addToast({
                        text: response?.data?.message,
                        type: toastType.ERROR,

                if (isLoading) {
                    dispatch(setLoading(false));
                }
            })
                );
            });
    };