import React from 'react';
import { decimalValidate, getFormattedMessage } from '../../shared/sharedMethod';
import { useDispatch } from 'react-redux';
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faTrash } from "@fortawesome/free-solid-svg-icons";

const PrintTable = ( props ) => {
    const { setUpdateProducts, updateProducts, printBarcodeValue } = props;
    const dispatch = useDispatch();

    const handleChange = ( e, singleProductId ) => {
        const rawValue = e.target.value;
        if ( rawValue === '' ) {
            setUpdateProducts( prev => prev.map( item =>
                item.id === singleProductId ? { ...item, quantity: '' } : item
            ) );
            return;
        }

        const parsedQty = parseInt( rawValue, 10 );
        if ( Number.isNaN( parsedQty ) || parsedQty < 1 ) {
            return;
        }

        setUpdateProducts( prev => prev.map( item =>
            item.id === singleProductId ? { ...item, quantity: parsedQty } : item
        ) );
        dispatch( { type: "UPDATE_PRINT_QTY", payload: parsedQty } );
    };

    const handleBlur = ( singleProductId ) => {
        setUpdateProducts( prev => prev.map( item =>
            item.id === singleProductId && ( !item.quantity || Number( item.quantity ) < 1 )
                ? { ...item, quantity: 1 }
                : item
        ) );
    };

    const onDeleteCartItem = ( id ) => {
        setUpdateProducts( prev => prev.filter( ( item ) => item.id !== id ) );
    };

    return (
        <tbody>
            {printBarcodeValue.warehouse_id && updateProducts && updateProducts.length >= 1 ? updateProducts.map( ( singleProduct, index ) => {
                const variationLabel = singleProduct.variation_name || singleProduct.variation_type || '';
                return (
                    <tr key={singleProduct.id ?? index} className='align-middle'>
                        <td className='ps-3'>
                            <h4 className='fs-6 mb-1'>{singleProduct.code}</h4>
                            <div className='d-flex align-items-center gap-2 flex-wrap'>
                                <span className='badge bg-light-success'>
                                    <span>{singleProduct.name}</span>
                                </span>
                                {variationLabel ? (
                                    <span className='badge bg-light-info'>
                                        <span>{variationLabel}</span>
                                    </span>
                                ) : null}
                            </div>
                        </td>
                        <td>
                            <input
                                type='number'
                                min={1}
                                step={1}
                                aria-label='Product Quantity'
                                className='form-control width-320'
                                onKeyPress={( event ) => decimalValidate( event )}
                                value={singleProduct.quantity ?? 1}
                                onChange={( e ) => handleChange( e, singleProduct.id )}
                                onBlur={() => handleBlur( singleProduct.id )}
                            />
                        </td>
                        <td className='text-start'>
                            <button
                                type='button'
                                className='btn px-4 text-danger fs-3'
                                onClick={() => onDeleteCartItem( singleProduct.id )}
                                aria-label='Remove Product'
                            >
                                <FontAwesomeIcon icon={faTrash} />
                            </button>
                        </td>
                    </tr> );
            } ) :
                <tr>
                    <td colSpan={8} className='fs-5 px-3 py-6 custom-text-center'>
                        {getFormattedMessage( 'sale.product.table.no-data.label' )}
                    </td>
                </tr>}
        </tbody> );

};
export default PrintTable;
