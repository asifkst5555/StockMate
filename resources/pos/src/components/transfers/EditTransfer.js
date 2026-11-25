import React, { useEffect } from 'react';
import { connect } from 'react-redux';
import { fetchAllWarehouses } from '../../store/action/warehouseAction';
import { useParams } from 'react-router-dom'
import HeaderTitle from '../header/HeaderTitle';
import MasterLayout from '../MasterLayout';
import { getFormattedMessage, getFormattedOptions } from '../../shared/sharedMethod';
import TransferForm from "./TransferForm";
import { fetchTransfer } from '../../store/action/transfersAction';
import { transferCreatStatusOptions } from '../../constants';

const EditTransfer = ( props ) => {
    const { fetchTransfer, tansfers, warehouses, fetchAllWarehouses, isLoading } = props;
    const { id } = useParams();

    useEffect( () => {
        fetchAllWarehouses();
        fetchTransfer( id );
    }, [] );

    const transferStatusFilterOptions = getFormattedOptions( transferCreatStatusOptions );
    const singleRecord = Array.isArray(tansfers)
        ? (tansfers.find((item) => String(item.id) === String(id)) || null)
        : (tansfers && tansfers.id && String(tansfers.id) === String(id) ? tansfers : (tansfers?.attributes ? tansfers : null));

    const transferStatusDefaultValue = singleRecord && singleRecord.attributes && singleRecord.attributes.status
        ? transferStatusFilterOptions.filter( ( item ) => item.id === singleRecord.attributes.status )
        : [];

    const itemsValue = singleRecord && singleRecord.attributes && {
        date: singleRecord.attributes.date,
        warehouse_id: {
            value: singleRecord.attributes.from_warehouse_id,
            label: singleRecord.attributes.from_warehouse?.name || '',
        },
        from_warehouse_id: {
            value: singleRecord.attributes.from_warehouse_id,
            label: singleRecord.attributes.from_warehouse?.name || '',
        },
        to_warehouse_id: {
            value: singleRecord.attributes.to_warehouse_id,
            label: singleRecord.attributes.to_warehouse?.name || '',
        },
        tax_rate: singleRecord.attributes.tax_rate,
        tax_amount: singleRecord.attributes.tax_amount,
        discount: singleRecord.attributes.discount,
        shipping: singleRecord.attributes.shipping,
        grand_total: singleRecord.attributes.grand_total,
        amount: singleRecord.attributes.amount,
        transfer_items: (singleRecord.attributes.transfer_items || []).map( ( item ) => ( {
            code: item.product && item.product.code,
            name: item.product && item.product.name,
            product_unit: item.product && item.product.product_unit,
            product_id: item.product_id,
            short_name: item.short_name,
            stock_alert: item.product && item.product.stock_alert,
            product_price: item.product_price,
            fix_net_unit: item.product_price,
            net_unit_price: item.product_price,
            net_unit_cost: item.product_price,
            tax_type: item.tax_type,
            tax_value: item.tax_value,
            tax_amount: item.tax_amount,
            discount_type: item.discount_type,
            discount_value: item.discount_value,
            discount_amount: item.discount_amount,
            isEdit: true,
            stock: item.product && item.product.stocks && Array.isArray(item.product.stocks) ? item.product.stocks.filter( s => s.warehouse_id === singleRecord.attributes.from_warehouse_id ) : [],
            sub_total: item.sub_total,
            sale_unit: item.sale_unit && item.sale_unit.id && item.sale_unit.id,
            quantity: item.quantity,
            id: item.id,
            transfer_item_id: item.id,
            newItem: '',
            purchase_unit: item.product && item.product.purchase_unit
        } ) ),
        id: singleRecord.id,
        notes: singleRecord.attributes.note,
        status_id: {
            label: transferStatusDefaultValue[ 0 ] && transferStatusDefaultValue[ 0 ].name,
            value: transferStatusDefaultValue[ 0 ] && transferStatusDefaultValue[ 0 ].id
        },
    };


    return (
        <MasterLayout>
            <HeaderTitle title={getFormattedMessage( 'transfer.edit.title' )} to='/app/user/transfers' />
            {isLoading ? <div className='text-center custom-loading mx-auto fs-1 fw-bold'>Loading...</div> :
                <TransferForm singleTransfer={itemsValue} id={id} warehouses={warehouses} />}
        </MasterLayout>
    )
};

const mapStateToProps = ( state ) => {
    const { warehouses, isLoading, tansfers } = state;
    return { warehouses, isLoading, tansfers }
};

export default connect( mapStateToProps, { fetchAllWarehouses, fetchTransfer } )( EditTransfer );

