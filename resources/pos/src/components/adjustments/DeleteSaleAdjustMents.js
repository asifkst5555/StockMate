import React, { useEffect, useRef, useState } from 'react';
import { connect } from 'react-redux';
import DeleteModel from '../../shared/action-buttons/DeleteModel';
import { deleteAdjustment } from '../../store/action/adjustMentAction';
import { getFormattedMessage } from '../../shared/sharedMethod';

const DeleteSale = (props) => {
    const { deleteAdjustment, onDelete, deleteModel, onClickDeleteModel } = props;
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

    const deleteAdjustMentClick = async () => {
        if (isDeletingRef.current || !onDelete?.id) {
            return;
        }
        isDeletingRef.current = true;
        setIsDeleting(true);
        try {
            const ok = await deleteAdjustment(onDelete.id);
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
                deleteClick={deleteAdjustMentClick} isDeleting={isDeleting} name={getFormattedMessage('adjustments.title')} />}
        </div>
    )
};

export default connect(null, { deleteAdjustment })(DeleteSale);
