import {transferActionType} from '../../constants';

export default (state = {}, action) => {
    switch (action.type) {
        case transferActionType.FETCH_TRANSFERS:
            return Array.isArray(action.payload)
                ? action.payload
                : (action.payload?.data && Array.isArray(action.payload.data)
                    ? action.payload.data
                    : (action.payload ? [action.payload] : []));
        case transferActionType.FETCH_TRANSFER:
            return action.payload;
        case transferActionType.ADD_TRANSFER:
            return action.payload;
        case transferActionType.EDIT_TRANSFER:
            if (Array.isArray(state)) {
                return state.map(item => item.id === +action.payload.id ? action.payload : item);
            }
            return action.payload;
        case transferActionType.DELETE_TRANSFER:
            if (Array.isArray(state)) {
                return state.filter(item => item.id !== action.payload);
            }
            return (state && state.id === action.payload) ? {} : state;
        default:
            return state;
    }
};
