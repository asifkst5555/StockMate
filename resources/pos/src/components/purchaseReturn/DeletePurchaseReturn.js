import React, {useEffect, useRef, useState} from 'react';
import {connect} from 'react-redux';
import {deletePurchaseReturn} from '../../store/action/purchaseReturnAction';
import DeleteModel from '../../shared/action-buttons/DeleteModel';
import {getFormattedMessage} from '../../shared/sharedMethod';

const DeletePurchaseReturn = (props) => {
    const {deletePurchaseReturn, onDelete, deleteModel, onClickDeleteModel} = props;
    const [isDeleting, setIsDeleting] = useState(false);
    const isDeletingRef = useRef(false);
    const isMountedRef = useRef(true);

    useEffect(() => {
        return () => {
            isMountedRef.current = false;
        };
    }, []);

    useEffect(() => {
        if (!deleteModel) {
            isDeletingRef.current = false;
            setIsDeleting(false);
        }
    }, [deleteModel]);

    const deleteClick = async () => {
        if (isDeletingRef.current || !onDelete?.id) {
            return;
        }
        isDeletingRef.current = true;
        setIsDeleting(true);
        try {
            const ok = await deletePurchaseReturn(onDelete.id);
            if (ok === true) {
                onClickDeleteModel(false);
            }
        } finally {
            isDeletingRef.current = false;
            if (isMountedRef.current) {
                setIsDeleting(false);
            }
        }
    };

    return (
        <div>
            {deleteModel && <DeleteModel onClickDeleteModel={onClickDeleteModel} deleteModel={deleteModel}
                                         deleteClick={deleteClick} isDeleting={isDeleting}
                                         name={getFormattedMessage('purchases.return.title')}/>}
        </div>
    )
};

export default connect(null, {deletePurchaseReturn})(DeletePurchaseReturn);
