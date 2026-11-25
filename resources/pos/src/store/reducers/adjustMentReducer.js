import {adjustMentActionType} from '../../constants';

export default (state = {}, action) => {
    switch (action.type) {
        case adjustMentActionType.FETCH_ADJUSTMENTS:
            return Array.isArray(action.payload)
                ? action.payload
                : (action.payload?.data && Array.isArray(action.payload.data)
                    ? action.payload.data
                    : (action.payload ? [action.payload] : []));
        case adjustMentActionType.FETCH_ADJUSTMENT:
            return action.payload;
        case adjustMentActionType.ADD_ADJUSTMENTS:
            return action.payload;
        case adjustMentActionType.EDIT_ADJUSTMENTS:
            if (Array.isArray(state)) {
                return state.map(item => item.id === +action.payload.id ? action.payload : item);
            }
            return action.payload;
        case adjustMentActionType.DELETE_ADJUSTMENT:
            if (Array.isArray(state)) {
                return state.filter(item => item.id !== action.payload);
            }
            return (state && state.id === action.payload) ? {} : state;
        default:
            return state;
    }
};