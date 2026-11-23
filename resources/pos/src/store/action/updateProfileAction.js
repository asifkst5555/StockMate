import apiConfig from '../../config/apiConfig';
import {apiBaseURL, toastType, profileActionType, Tokens} from '../../constants';
import {addToast} from './toastAction'
import {getFormattedMessage} from '../../shared/sharedMethod';

const syncProfileToStorage = (attributes = {}) => {
    const imageUrl = attributes.image || '';
    localStorage.setItem(Tokens.USER_IMAGE_URL, imageUrl);
    localStorage.setItem(Tokens.IMAGE, imageUrl);
    if (attributes.email !== undefined) {
        localStorage.setItem(Tokens.UPDATED_EMAIL, attributes.email || '');
        localStorage.setItem(Tokens.USER, attributes.email || '');
    }
    if (attributes.first_name !== undefined) {
        localStorage.setItem(Tokens.UPDATED_FIRST_NAME, attributes.first_name || '');
        localStorage.setItem(Tokens.FIRST_NAME, attributes.first_name || '');
    }
    if (attributes.last_name !== undefined) {
        localStorage.setItem(Tokens.UPDATED_LAST_NAME, attributes.last_name || '');
        localStorage.setItem(Tokens.LAST_NAME, attributes.last_name || '');
    }
    try {
        const raw = localStorage.getItem('loginUserArray');
        if (raw) {
            const parsed = JSON.parse(raw);
            parsed.image_url = imageUrl;
            if (attributes.first_name !== undefined) parsed.first_name = attributes.first_name;
            if (attributes.last_name !== undefined) parsed.last_name = attributes.last_name;
            if (attributes.email !== undefined) parsed.email = attributes.email;
            if (attributes.phone !== undefined) parsed.phone = attributes.phone;
            localStorage.setItem('loginUserArray', JSON.stringify(parsed));
        }
    } catch (e) {
        // Ignore storage parse errors
    }
};

export const fetchProfile = () => async (dispatch) => {
    return apiConfig.get(apiBaseURL.EDIT_PROFILE)
        .then((response) => {
            const profileData = response.data.data;
            dispatch({type: profileActionType.FETCH_PROFILE, payload: profileData});
            if (profileData?.attributes) {
                syncProfileToStorage(profileData.attributes);
            }
            return { success: true, data: profileData };
        })
        .catch(({response}) => {
            dispatch(addToast(
                {text: response?.data?.message || 'Failed to load profile.', type: toastType.ERROR}));
            return { success: false, message: response?.data?.message };
        });
};

export const updateProfile = (profile) => async (dispatch) => {
    return apiConfig.post(apiBaseURL.UPDATE_PROFILE, profile)
        .then((response) => {
            const profileData = response.data.data;
            dispatch({type: profileActionType.UPDATE_PROFILE, payload: profileData});
            if (profileData?.attributes) {
                syncProfileToStorage(profileData.attributes);
            }
            dispatch(addToast({text: getFormattedMessage('update-profile.success.update.message')}));
            return { success: true, data: profileData };
        })
        .catch(({response}) => {
            const errorMsg = response?.data?.message || 'Failed to update profile.';
            dispatch(addToast({text: errorMsg, type: toastType.ERROR}));
            return { success: false, message: errorMsg, errors: response?.data?.errors };
        });
};
