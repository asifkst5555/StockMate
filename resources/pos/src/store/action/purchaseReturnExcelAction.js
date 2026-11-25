import { downloadProtectedFile } from '../../shared/downloadProtectedFile';
import apiConfig from '../../config/apiConfig';
import {toastType} from '../../constants';
import {addToast} from './toastAction';
import {setLoading} from './loadingAction';

export const purchaseReturnExcelAction = (warehouse, setIsWarehouseValue, filter = {}, isLoading = true) => async (dispatch) => {
    if (isLoading) {
        dispatch(setLoading(true))
    }
    await apiConfig.get(`purchases-return-report-excel?warehouse_id=${warehouse}`)
        .then(async (response) => {
            await downloadProtectedFile(response.data.data.purchase_return_excel_url);
            setIsWarehouseValue(false);
            if (isLoading) {
                dispatch(setLoading(false))
            }
        })
        .catch(({response}) => {
            dispatch(addToast(
                {text: response?.data?.message, type: toastType.ERROR}));

            if (isLoading) {
                dispatch(setLoading(false));
            }
        });
};
